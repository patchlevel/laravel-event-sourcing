<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Patchlevel\EventSourcing\Clock\FrozenClock;
use Patchlevel\EventSourcing\Subscription\RunMode;
use Patchlevel\EventSourcing\Subscription\Status;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionAlreadyExists;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionCriteria;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionNotFound;
use Patchlevel\EventSourcing\Subscription\Subscription;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\DropIndexTask;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\DropTableTask;
use Patchlevel\LaravelEventSourcing\Subscription\Store\IlluminateSubscriptionStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_map;
use function str_repeat;

/**
 * Mirrors the DoctrineSubscriptionStoreTest of patchlevel/event-sourcing, so both stores fulfill the same contract.
 * The schema tests are left out, the schema is managed by the laravel migration here.
 */
#[CoversNothing]
final class IlluminateSubscriptionStoreTest extends IntegrationTestCase
{
    private FrozenClock $clock;
    private IlluminateSubscriptionStore $store;

    public function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2021-01-01T00:00:00'));
        $this->store = new IlluminateSubscriptionStore($this->connection, $this->clock);
    }

    public function testAddAndGet(): void
    {
        $this->store->add(new Subscription(
            'foo',
            'bar',
            RunMode::FromNow,
            Status::Active,
            10,
            null,
            2,
            null,
            [new DropTableTask('baz')],
        ));

        $loaded = $this->store->get('foo');

        self::assertSame('foo', $loaded->id());
        self::assertSame('bar', $loaded->group());
        self::assertSame(RunMode::FromNow, $loaded->runMode());
        self::assertSame(Status::Active, $loaded->status());
        self::assertSame(10, $loaded->position());
        self::assertNull($loaded->subscriptionError());
        self::assertSame(2, $loaded->retryAttempt());
        self::assertEquals(new DateTimeImmutable('2021-01-01T00:00:00'), $loaded->lastSavedAt());
        self::assertEquals([new DropTableTask('baz')], $loaded->cleanupTasks());
    }

    public function testLastSavedAtKeepsTheWallClockTime(): void
    {
        $now = new DateTimeImmutable('2020-01-01 10:00:00', new DateTimeZone('America/New_York'));
        $store = new IlluminateSubscriptionStore($this->connection, new FrozenClock($now));

        $store->add(new Subscription('foo'));

        $lastSavedAt = $store->get('foo')->lastSavedAt();

        self::assertNotNull($lastSavedAt);
        self::assertSame($now->format('Y-m-d H:i:s'), $lastSavedAt->format('Y-m-d H:i:s'));
    }

    public function testAddSetsLastSavedAt(): void
    {
        $subscription = new Subscription('foo');

        $this->store->add($subscription);

        self::assertEquals(new DateTimeImmutable('2021-01-01T00:00:00'), $subscription->lastSavedAt());
    }

    public function testAddDuplicateSubscription(): void
    {
        $this->store->add(new Subscription('foo', position: 42));

        $exception = null;

        try {
            $this->store->add(new Subscription('foo'));
        } catch (SubscriptionAlreadyExists $e) {
            $exception = $e;
        }

        self::assertInstanceOf(SubscriptionAlreadyExists::class, $exception);
        self::assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        self::assertSame(42, $this->store->get('foo')->position());
    }

    public function testGetUnknownSubscription(): void
    {
        $this->expectException(SubscriptionNotFound::class);

        $this->store->get('foo');
    }

    public function testUpdate(): void
    {
        $this->store->add(new Subscription('foo'));

        $subscription = $this->store->get('foo');
        $subscription->active();
        $subscription->changePosition(42);

        $this->store->update($subscription);

        $loaded = $this->store->get('foo');

        self::assertSame(Status::Active, $loaded->status());
        self::assertSame(42, $loaded->position());
    }

    public function testUpdateWithoutChanges(): void
    {
        $this->store->add(new Subscription('foo'));

        // the clock is frozen, so all values stay the same and mysql reports no affected rows
        $this->store->update($this->store->get('foo'));
        $this->store->update($this->store->get('foo'));

        self::assertSame('foo', $this->store->get('foo')->id());
    }

    public function testUpdateUnknownSubscription(): void
    {
        $this->expectException(SubscriptionNotFound::class);

        $this->store->update(new Subscription('foo'));
    }

    public function testUpdateAllFields(): void
    {
        $this->store->add(new Subscription('foo'));

        $subscription = $this->store->get('foo');
        $subscription->changeGroup('bar');
        $subscription->changeRunMode(RunMode::Once);
        $subscription->changePosition(42);
        $subscription->replaceCleanupTasks([new DropTableTask('baz')]);

        $this->store->update($subscription);

        $loaded = $this->store->get('foo');

        self::assertSame('bar', $loaded->group());
        self::assertSame(RunMode::Once, $loaded->runMode());
        self::assertSame(42, $loaded->position());
        self::assertEquals([new DropTableTask('baz')], $loaded->cleanupTasks());
    }

    public function testUpdateWithError(): void
    {
        $this->store->add(new Subscription('foo', status: Status::Active));

        $subscription = $this->store->get('foo');
        $subscription->error(new RuntimeException('error message'));

        $this->store->update($subscription);

        $loaded = $this->store->get('foo');

        self::assertSame(Status::Error, $loaded->status());

        $error = $loaded->subscriptionError();

        self::assertNotNull($error);
        self::assertSame('error message', $error->errorMessage);
        self::assertSame(Status::Active, $error->previousStatus);
        self::assertNotNull($error->errorContext);
        self::assertSame(RuntimeException::class, $error->errorContext[0]['class']);
        self::assertSame('error message', $error->errorContext[0]['message']);
    }

    public function testUpdateWithFailedFromString(): void
    {
        $this->store->add(new Subscription('foo', status: Status::Active));

        $subscription = $this->store->get('foo');
        $subscription->failed('error message');

        $this->store->update($subscription);

        $loaded = $this->store->get('foo');

        self::assertSame(Status::Failed, $loaded->status());

        $error = $loaded->subscriptionError();

        self::assertNotNull($error);
        self::assertSame('error message', $error->errorMessage);
        self::assertSame(Status::Active, $error->previousStatus);
        self::assertNull($error->errorContext);
    }

    public function testUpdateWithNestedErrors(): void
    {
        $this->store->add(new Subscription('foo', status: Status::Booting));

        $subscription = $this->store->get('foo');
        $subscription->error(new RuntimeException('outer', 0, new LogicException('inner')));

        $this->store->update($subscription);

        $error = $this->store->get('foo')->subscriptionError();

        self::assertNotNull($error);
        self::assertSame('outer', $error->errorMessage);
        self::assertSame(Status::Booting, $error->previousStatus);
        self::assertNotNull($error->errorContext);
        self::assertCount(2, $error->errorContext);
        self::assertSame(RuntimeException::class, $error->errorContext[0]['class']);
        self::assertSame('outer', $error->errorContext[0]['message']);
        self::assertSame(LogicException::class, $error->errorContext[1]['class']);
        self::assertSame('inner', $error->errorContext[1]['message']);
    }

    public function testUpdateWithLongUnicodeErrorMessage(): void
    {
        // no 4-byte characters like emojis, the ci uses charset=utf8 (utf8mb3) for mariadb
        $message = str_repeat('Fehler: äöü ß € ', 1000);

        $this->store->add(new Subscription('foo'));

        $subscription = $this->store->get('foo');
        $subscription->error(new RuntimeException($message));

        $this->store->update($subscription);

        $error = $this->store->get('foo')->subscriptionError();

        self::assertNotNull($error);
        self::assertSame($message, $error->errorMessage);
        self::assertNotNull($error->errorContext);
        self::assertSame($message, $error->errorContext[0]['message']);
    }

    public function testUpdateRefreshesLastSavedAt(): void
    {
        $this->store->add(new Subscription('foo'));

        $this->clock->update(new DateTimeImmutable('2021-01-02T12:30:00'));

        $subscription = $this->store->get('foo');
        $this->store->update($subscription);

        self::assertEquals(new DateTimeImmutable('2021-01-02T12:30:00'), $subscription->lastSavedAt());
        self::assertEquals(new DateTimeImmutable('2021-01-02T12:30:00'), $this->store->get('foo')->lastSavedAt());
    }

    public function testUpdateAfterRetry(): void
    {
        $this->store->add(new Subscription('foo', status: Status::Active));

        $subscription = $this->store->get('foo');
        $subscription->error('error message');
        $this->store->update($subscription);

        $subscription = $this->store->get('foo');
        $subscription->doRetry();
        $this->store->update($subscription);

        $loaded = $this->store->get('foo');

        self::assertSame(Status::Active, $loaded->status());
        self::assertNull($loaded->subscriptionError());
        self::assertSame(1, $loaded->retryAttempt());
    }

    public function testUpdateResetCleanupTasks(): void
    {
        $this->store->add(new Subscription('foo', cleanupTasks: [new DropTableTask('baz')]));

        $subscription = $this->store->get('foo');
        $subscription->replaceCleanupTasks(null);

        $this->store->update($subscription);

        self::assertNull($this->store->get('foo')->cleanupTasks());
    }

    public function testUpdateWithChangesAfterUpdateWithoutChanges(): void
    {
        $this->store->add(new Subscription('foo'));

        $this->store->update($this->store->get('foo'));

        $subscription = $this->store->get('foo');
        $subscription->changePosition(42);
        $this->store->update($subscription);

        self::assertSame(42, $this->store->get('foo')->position());
    }

    public function testUpdateDoesNotAffectOtherSubscriptions(): void
    {
        $this->store->add(new Subscription('foo'));
        $this->store->add(new Subscription('bar'));

        $subscription = $this->store->get('foo');
        $subscription->changePosition(42);
        $this->store->update($subscription);

        self::assertSame(42, $this->store->get('foo')->position());
        self::assertSame(0, $this->store->get('bar')->position());
    }

    public function testUpdateRemovedSubscription(): void
    {
        $this->store->add(new Subscription('foo'));

        $subscription = $this->store->get('foo');
        $this->store->remove($subscription);

        $this->expectException(SubscriptionNotFound::class);

        $this->store->update($subscription);
    }

    public function testRemove(): void
    {
        $this->store->add(new Subscription('foo'));
        $this->store->add(new Subscription('bar'));

        $this->store->remove($this->store->get('foo'));

        self::assertSame(['bar'], $this->ids($this->store->find()));
    }

    public function testRemoveUnknownSubscription(): void
    {
        $this->store->add(new Subscription('foo'));

        $this->store->remove(new Subscription('bar'));

        self::assertSame(['foo'], $this->ids($this->store->find()));
    }

    public function testRemoveAndAddAgain(): void
    {
        $this->store->add(new Subscription('foo', position: 42));
        $this->store->remove($this->store->get('foo'));

        $this->store->add(new Subscription('foo'));

        self::assertSame(0, $this->store->get('foo')->position());
    }

    public function testFindWithoutCriteria(): void
    {
        $this->store->add(new Subscription('c'));
        $this->store->add(new Subscription('a'));
        $this->store->add(new Subscription('b'));

        self::assertSame(['a', 'b', 'c'], $this->ids($this->store->find()));
    }

    public function testFindEmpty(): void
    {
        self::assertSame([], $this->store->find());
    }

    public function testFindByIds(): void
    {
        $this->store->add(new Subscription('a'));
        $this->store->add(new Subscription('b'));
        $this->store->add(new Subscription('c'));

        self::assertSame(
            ['a', 'c'],
            $this->ids($this->store->find(new SubscriptionCriteria(ids: ['a', 'c', 'unknown']))),
        );
    }

    public function testFindByGroups(): void
    {
        $this->store->add(new Subscription('a', 'foo'));
        $this->store->add(new Subscription('b', 'bar'));
        $this->store->add(new Subscription('c', 'baz'));

        self::assertSame(
            ['a', 'b'],
            $this->ids($this->store->find(new SubscriptionCriteria(groups: ['foo', 'bar']))),
        );
    }

    public function testFindByUnknownGroup(): void
    {
        $this->store->add(new Subscription('foo'));

        self::assertSame([], $this->store->find(new SubscriptionCriteria(groups: ['unknown'])));
    }

    public function testFindByStatus(): void
    {
        $this->store->add(new Subscription('a', status: Status::Active));
        $this->store->add(new Subscription('b', status: Status::Error));
        $this->store->add(new Subscription('c', status: Status::New));

        self::assertSame(
            ['a', 'b'],
            $this->ids($this->store->find(new SubscriptionCriteria(status: [Status::Active, Status::Error]))),
        );
    }

    public function testFindByCombinedCriteria(): void
    {
        $this->store->add(new Subscription('a', 'foo', status: Status::Active));
        $this->store->add(new Subscription('b', 'foo', status: Status::New));
        $this->store->add(new Subscription('c', 'bar', status: Status::Active));
        $this->store->add(new Subscription('d', 'foo', status: Status::Active));

        self::assertSame(
            ['a'],
            $this->ids($this->store->find(new SubscriptionCriteria(
                ids: ['a', 'b', 'c'],
                groups: ['foo'],
                status: [Status::Active],
            ))),
        );
    }

    public function testFindWithEmptyCriteriaLists(): void
    {
        $this->store->add(new Subscription('a'));

        self::assertSame([], $this->store->find(new SubscriptionCriteria(ids: [])));
        self::assertSame([], $this->store->find(new SubscriptionCriteria(groups: [])));
        self::assertSame([], $this->store->find(new SubscriptionCriteria(status: [])));
    }

    public function testFindHydratesAllFields(): void
    {
        $subscription = new Subscription(
            'foo',
            'bar',
            RunMode::Once,
            Status::Active,
            10,
            null,
            3,
            null,
            [new DropTableTask('baz')],
        );
        $subscription->error('error message');

        $this->store->add($subscription);

        $found = $this->store->find(new SubscriptionCriteria(ids: ['foo']));

        self::assertCount(1, $found);
        self::assertEquals($subscription, $found[0]);
    }

    public function testInLock(): void
    {
        $this->store->add(new Subscription('foo'));

        $result = $this->store->inLock(function (): string {
            $subscription = $this->store->find(new SubscriptionCriteria(ids: ['foo']))[0];
            $subscription->changePosition(42);
            $this->store->update($subscription);

            return $subscription->id();
        });

        self::assertSame('foo', $result);
        self::assertSame(42, $this->store->get('foo')->position());
        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function testInLockWithUpdateWithoutChanges(): void
    {
        $this->store->add(new Subscription('foo'));

        $this->store->inLock(function (): void {
            foreach ($this->store->find() as $subscription) {
                $this->store->update($subscription);
            }
        });

        self::assertSame(0, $this->connection->transactionLevel());
        self::assertSame('foo', $this->store->get('foo')->id());
    }

    public function testInLockCommitsWhenClosureThrows(): void
    {
        $this->store->add(new Subscription('foo'));

        $exception = null;

        try {
            $this->store->inLock(function (): void {
                $subscription = $this->store->get('foo');
                $subscription->changePosition(42);
                $this->store->update($subscription);

                throw new RuntimeException('error');
            });
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        self::assertNotNull($exception);
        self::assertSame('error', $exception->getMessage());
        self::assertSame(0, $this->connection->transactionLevel());
        self::assertSame(42, $this->store->get('foo')->position());
    }

    public function testCustomTableName(): void
    {
        // the schema comes from the migration, so its table is renamed instead of created
        $this->connection->getSchemaBuilder()->rename('subscriptions', 'custom_subscriptions');

        $store = new IlluminateSubscriptionStore($this->connection, $this->clock, 'custom_subscriptions');

        $store->add(new Subscription('foo'));

        $subscription = $store->get('foo');
        $subscription->changePosition(42);
        $store->update($subscription);

        self::assertSame(42, $store->get('foo')->position());
        self::assertSame(1, $this->connection->table('custom_subscriptions')->count());
    }

    #[DataProvider('statusProvider')]
    public function testStatusRoundTrip(Status $status): void
    {
        $this->store->add(new Subscription('foo', status: $status));

        self::assertSame($status, $this->store->get('foo')->status());
        self::assertSame(['foo'], $this->ids($this->store->find(new SubscriptionCriteria(status: [$status]))));
    }

    public static function statusProvider(): Generator
    {
        foreach (Status::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('runModeProvider')]
    public function testRunModeRoundTrip(RunMode $runMode): void
    {
        $this->store->add(new Subscription('foo', runMode: $runMode));

        self::assertSame($runMode, $this->store->get('foo')->runMode());
    }

    public static function runModeProvider(): Generator
    {
        foreach (RunMode::cases() as $runMode) {
            yield $runMode->value => [$runMode];
        }
    }

    public function testMaxValues(): void
    {
        $id = str_repeat('a', 255);
        $group = str_repeat('b', 32);

        $this->store->add(new Subscription($id, $group, position: 2147483647, retryAttempt: 2147483647));

        $loaded = $this->store->get($id);

        self::assertSame($id, $loaded->id());
        self::assertSame($group, $loaded->group());
        self::assertSame(2147483647, $loaded->position());
        self::assertSame(2147483647, $loaded->retryAttempt());
    }

    public function testMultipleCleanupTasks(): void
    {
        $tasks = [
            new DropTableTask('foo', 'connection'),
            new DropIndexTask('bar_idx', 'bar'),
        ];

        $this->store->add(new Subscription('foo', cleanupTasks: $tasks));

        self::assertEquals($tasks, $this->store->get('foo')->cleanupTasks());
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<string>
     */
    private function ids(array $subscriptions): array
    {
        return array_map(static fn (Subscription $subscription) => $subscription->id(), $subscriptions);
    }
}
