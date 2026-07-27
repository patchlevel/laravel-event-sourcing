<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Patchlevel\EventSourcing\Clock\FrozenClock;
use Patchlevel\EventSourcing\Subscription\RunMode;
use Patchlevel\EventSourcing\Subscription\Status;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionCriteria;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionNotFound;
use Patchlevel\EventSourcing\Subscription\Subscription;
use Patchlevel\LaravelEventSourcing\Subscription\Store\IlluminateSubscriptionStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

use function array_map;

#[CoversNothing]
final class IlluminateSubscriptionStoreTest extends IntegrationTestCase
{
    private IlluminateSubscriptionStore $store;

    private DateTimeImmutable $now;

    public function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2020-01-01 10:00:00', new DateTimeZone('America/New_York'));
        $this->store = new IlluminateSubscriptionStore(
            $this->connection,
            new FrozenClock($this->now),
        );
    }

    public function testAddAndGet(): void
    {
        $this->store->add(new Subscription('foo', 'default', RunMode::FromBeginning, Status::New));

        $subscription = $this->store->get('foo');

        self::assertSame('foo', $subscription->id());
        self::assertSame('default', $subscription->group());
        self::assertSame(RunMode::FromBeginning, $subscription->runMode());
        self::assertSame(Status::New, $subscription->status());
        self::assertSame(0, $subscription->position());
        self::assertNull($subscription->subscriptionError());
    }

    public function testLastSavedAtKeepsTheWallClockTime(): void
    {
        $this->store->add(new Subscription('foo'));

        $lastSavedAt = $this->store->get('foo')->lastSavedAt();

        self::assertNotNull($lastSavedAt);
        self::assertSame($this->now->format('Y-m-d H:i:s'), $lastSavedAt->format('Y-m-d H:i:s'));
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

        $updated = $this->store->get('foo');

        self::assertSame(Status::Active, $updated->status());
        self::assertSame(42, $updated->position());
    }

    public function testUpdateUnknownSubscription(): void
    {
        $this->expectException(SubscriptionNotFound::class);

        $this->store->update(new Subscription('foo'));
    }

    public function testError(): void
    {
        $subscription = new Subscription('foo', 'default', RunMode::FromBeginning, Status::Active);
        $subscription->error(new RuntimeException('boom'));

        $this->store->add($subscription);

        $loaded = $this->store->get('foo');
        $error = $loaded->subscriptionError();

        self::assertSame(Status::Error, $loaded->status());
        self::assertNotNull($error);
        self::assertSame('boom', $error->errorMessage);
        self::assertSame(Status::Active, $error->previousStatus);
        self::assertNotNull($error->errorContext);
        self::assertSame(RuntimeException::class, $error->errorContext[0]['class']);
    }

    public function testRemove(): void
    {
        $this->store->add(new Subscription('foo'));

        $this->store->remove($this->store->get('foo'));

        $this->expectException(SubscriptionNotFound::class);

        $this->store->get('foo');
    }

    public function testFind(): void
    {
        $this->store->add(new Subscription('foo', 'default', RunMode::FromBeginning, Status::Active));
        $this->store->add(new Subscription('bar', 'other', RunMode::FromNow, Status::New));

        self::assertSame(['bar', 'foo'], $this->findIds());
        self::assertSame(['foo'], $this->findIds(new SubscriptionCriteria(ids: ['foo'])));
        self::assertSame(['bar'], $this->findIds(new SubscriptionCriteria(groups: ['other'])));
        self::assertSame(['foo'], $this->findIds(new SubscriptionCriteria(status: [Status::Active])));
    }

    public function testInLockCommits(): void
    {
        $this->store->add(new Subscription('foo'));

        $result = $this->store->inLock(function (): string {
            $subscription = $this->store->get('foo');
            $subscription->changePosition(7);
            $this->store->update($subscription);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(7, $this->store->get('foo')->position());
    }

    /** @return list<string> */
    private function findIds(SubscriptionCriteria|null $criteria = null): array
    {
        return array_map(
            static fn (Subscription $subscription): string => $subscription->id(),
            $this->store->find($criteria),
        );
    }
}
