<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Illuminate\Support\Facades\Mail;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Status;
use Patchlevel\EventSourcing\Subscription\Subscription;
use Patchlevel\LaravelEventSourcing\Facade\CommandBus;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Application\Mail\WelcomeMail;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_combine;
use function array_map;

#[CoversNothing]
final class SubscriptionCommandTest extends ApplicationTestCase
{
    public function testSubscriptionsAreActiveAfterSetupAndBoot(): void
    {
        $subscriptions = $this->app->get(SubscriptionEngine::class)->subscriptions();

        self::assertEqualsCanonicalizing(
            ['profile' => Status::Active, 'welcome_mail' => Status::Active],
            array_combine(
                array_map(static fn (Subscription $subscription) => $subscription->id(), $subscriptions),
                array_map(static fn (Subscription $subscription) => $subscription->status(), $subscriptions),
            ),
        );
        self::assertSame([], $this->projector()->names());
    }

    public function testRunCommandHandlesTheEventsWhenNotRunAfterSave(): void
    {
        $this->reloadApplicationWithConfig([
            'event-sourcing.subscription.run_after_aggregate_save.enabled' => false,
        ]);

        Mail::fake();

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        self::assertSame([], $this->projector()->names());
        Mail::assertNothingSent();

        $this->artisan('event-sourcing:subscription:run', ['--run-limit' => 1])->assertSuccessful();

        self::assertSame(['John'], $this->projector()->names());
        Mail::assertSent(WelcomeMail::class, static fn (WelcomeMail $mail) => $mail->hasTo('john@example.com'));
    }

    public function testProjectionIsRebuiltWithSetupAndBootWithoutRunningTheProcessorAgain(): void
    {
        Mail::fake();

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));
        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'Jane', 'jane@example.com'));

        $this->removeSubscription();

        self::assertSame(Status::New, $this->subscriptionStatus());

        $this->artisan('event-sourcing:subscription:setup', ['--id' => ['profile']])->assertSuccessful();

        self::assertSame(Status::Booting, $this->subscriptionStatus());
        self::assertSame([], $this->projector()->names());

        $this->artisan('event-sourcing:subscription:boot', ['--id' => ['profile']])->assertSuccessful();

        self::assertSame(Status::Active, $this->subscriptionStatus());
        self::assertSame(['Jane', 'John'], $this->projector()->names());
        Mail::assertSentCount(2);
    }

    public function testNewProcessorOnlyHandlesNewEvents(): void
    {
        Mail::fake();

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        // a processor runs from now on, so a new setup must not send mails for the past events
        $this->artisan('event-sourcing:subscription:remove', ['--id' => ['welcome_mail']])->assertSuccessful();
        $this->artisan('event-sourcing:subscription:setup', ['--id' => ['welcome_mail']])->assertSuccessful();
        $this->artisan('event-sourcing:subscription:boot', ['--id' => ['welcome_mail']])->assertSuccessful();

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'Jane', 'jane@example.com'));

        Mail::assertSentCount(2);
        Mail::assertSent(WelcomeMail::class, static fn (WelcomeMail $mail) => $mail->hasTo('jane@example.com'));
    }

    public function testPauseAndReactivate(): void
    {
        $this->artisan('event-sourcing:subscription:pause', ['--id' => ['profile']])->assertSuccessful();

        self::assertSame(Status::Paused, $this->subscriptionStatus());

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        self::assertSame([], $this->projector()->names());

        $this->artisan('event-sourcing:subscription:reactivate', ['--id' => ['profile']])->assertSuccessful();
        $this->artisan('event-sourcing:subscription:run', ['--run-limit' => 1])->assertSuccessful();

        self::assertSame(Status::Active, $this->subscriptionStatus());
        self::assertSame(['John'], $this->projector()->names());
    }

    public function testStatusCommandListsTheSubscription(): void
    {
        $this->artisan('event-sourcing:subscription:status')
            ->expectsOutputToContain('profile')
            ->assertSuccessful();
    }
}
