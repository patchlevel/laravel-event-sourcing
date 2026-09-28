<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Patchlevel\LaravelEventSourcing\Facade\CommandBus;
use Patchlevel\LaravelEventSourcing\Facade\QueryBus;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\ChangeName;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Application\Mail\WelcomeMail;
use Patchlevel\LaravelEventSourcing\Tests\Application\Query\QueryProfileName;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

#[CoversNothing]
final class ProfileTest extends ApplicationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function testRegisterProfile(): void
    {
        $id = ProfileId::generate();

        CommandBus::dispatch(new CreateProfile($id, 'John', 'john@example.com'));

        self::assertSame(1, DB::table('event_store')->where('stream', 'profile-' . $id->toString())->count());
        self::assertSame('John', Profile::load($id)->name());
        self::assertSame('John', QueryBus::dispatch(new QueryProfileName($id)));
    }

    public function testWelcomeMailIsSentOnRegistration(): void
    {
        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        Mail::assertSent(
            WelcomeMail::class,
            static fn (WelcomeMail $mail) => $mail->hasTo('john@example.com') && $mail->name === 'John',
        );
        Mail::assertSentCount(1);
    }

    public function testChangeName(): void
    {
        $id = ProfileId::generate();

        CommandBus::dispatch(new CreateProfile($id, 'John', 'john@example.com'));
        CommandBus::dispatch(new ChangeName($id, 'Jane'));

        $profile = Profile::load($id);

        self::assertSame('Jane', $profile->name());
        self::assertSame(2, $profile->playhead());
        self::assertSame('Jane', QueryBus::dispatch(new QueryProfileName($id)));
        Mail::assertSentCount(1);
    }

    public function testQueryUnknownProfile(): void
    {
        self::assertNull(QueryBus::dispatch(new QueryProfileName(ProfileId::generate())));
    }

    /**
     * The store uses the default laravel connection on purpose: an application can use event sourced
     * aggregates next to eloquent models, and both take part in the same transaction.
     */
    public function testStoreTakesPartInTheApplicationTransaction(): void
    {
        $id = ProfileId::generate();

        try {
            DB::transaction(static function () use ($id): void {
                CommandBus::dispatch(new CreateProfile($id, 'John', 'john@example.com'));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertFalse(Profile::has($id));
        self::assertSame(0, DB::table('event_store')->count());
    }
}
