<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Patchlevel\EventSourcing\Subscription\Status;
use Patchlevel\LaravelEventSourcing\Facade\CommandBus;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Application\Projection\ProfileProjector;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionClass;

use function clearstatcache;
use function filemtime;
use function touch;

#[CoversNothing]
final class MiddlewareTest extends ApplicationTestCase
{
    private string $projectorFile;
    private int $projectorModifiedTime;

    public function setUp(): void
    {
        parent::setUp();

        $this->projectorFile = (string)(new ReflectionClass(ProfileProjector::class))->getFileName();
        $this->projectorModifiedTime = (int)filemtime($this->projectorFile);
    }

    public function tearDown(): void
    {
        $this->touchProjector($this->projectorModifiedTime);

        parent::tearDown();
    }

    public function testNewSubscriptionsAreSetUpOnRequest(): void
    {
        $this->removeSubscription();

        self::assertSame(Status::New, $this->subscriptionStatus());

        $this->get('/')->assertOk()->assertSee('ok');

        self::assertSame(Status::Active, $this->subscriptionStatus());
    }

    public function testNewSubscriptionsAreNotSetUpWhenAutoSetupIsDisabled(): void
    {
        $this->reloadApplicationWithConfig([
            'event-sourcing.subscription.auto_setup.enabled' => false,
        ]);
        $this->removeSubscription();

        $this->get('/')->assertOk();

        self::assertSame(Status::New, $this->subscriptionStatus());
    }

    public function testAutoSetupIsRestrictedToTheConfiguredGroups(): void
    {
        $this->reloadApplicationWithConfig([
            'event-sourcing.subscription.auto_setup.groups' => ['other'],
        ]);
        $this->removeSubscription();

        $this->get('/')->assertOk();

        self::assertSame(Status::New, $this->subscriptionStatus());
    }

    public function testSubscriptionIsRebuiltWhenItsFileChanged(): void
    {
        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        // the first request remembers the file modification time
        $this->get('/')->assertOk();

        $this->projector()->connection->table('projection_profile')->delete();

        $this->get('/')->assertOk();

        self::assertSame([], $this->projector()->names(), 'an unchanged file must not trigger a rebuild');

        $this->touchProjector($this->projectorModifiedTime + 10);

        $this->get('/')->assertOk();

        self::assertSame(Status::Active, $this->subscriptionStatus());
        self::assertSame(['John'], $this->projector()->names());
    }

    public function testSubscriptionIsNotRebuiltWhenRebuildIsDisabled(): void
    {
        $this->reloadApplicationWithConfig([
            'event-sourcing.subscription.rebuild_after_file_change.enabled' => false,
        ]);

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        $this->get('/')->assertOk();

        $this->projector()->connection->table('projection_profile')->delete();
        $this->touchProjector($this->projectorModifiedTime + 10);

        $this->get('/')->assertOk();

        self::assertSame([], $this->projector()->names());
    }

    public function testSubscriptionIsNotRebuiltInProduction(): void
    {
        $this->app['env'] = 'production';

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        $this->get('/')->assertOk();

        $this->projector()->connection->table('projection_profile')->delete();
        $this->touchProjector($this->projectorModifiedTime + 10);

        $this->get('/')->assertOk();

        self::assertSame([], $this->projector()->names());
    }

    private function touchProjector(int $time): void
    {
        touch($this->projectorFile, $time);
        clearstatcache();
    }
}
