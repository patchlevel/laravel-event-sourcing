<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as Queueing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Patchlevel\EventSourcing\Aggregate\CustomId;
use Patchlevel\EventSourcing\CommandBus\CommandBus;
use Patchlevel\EventSourcing\CommandBus\HandlerNotFound;
use Patchlevel\EventSourcing\CommandBus\InstantRetryCommandBus;
use Patchlevel\EventSourcing\EventBus\DefaultEventBus;
use Patchlevel\EventSourcing\EventBus\EventBus;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\EventSourcing\QueryBus\InvalidQueryHandler;
use Patchlevel\EventSourcing\QueryBus\QueryBus;
use Patchlevel\EventSourcing\QueryBus\SyncQueryBus;
use Patchlevel\EventSourcing\Repository\RepositoryManager;
use Patchlevel\LaravelEventSourcing\EventBus\DispatchMessageJob;
use Patchlevel\LaravelEventSourcing\EventBus\IlluminateEventBus;
use Patchlevel\LaravelEventSourcing\EventBus\QueueEventBus;
use Patchlevel\LaravelEventSourcing\QueryBus\IlluminateQueryBus;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\CustomCommandBus;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\Profile;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\ProfileCreated;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\ProfileProjector;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\QueryFoo;

use function count;

final class IlluminateBusTest extends TestCase
{
    public function testAggregateHandlersAreNotRegisteredForTheDefaultType(): void
    {
        self::assertFalse(Bus::hasCommandHandler(new CreateProfile(new CustomId('1'))));
    }

    /**
     * A config file that was published before the type option existed has no type at all,
     * because `mergeConfigFrom()` merges only the first level.
     */
    public function testConfigWithoutTypeKeepsTheDefaultBus(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.command_bus' => [
                'enabled' => true,
                'instant_retry' => ['max_retries' => 3, 'exceptions' => []],
            ],
            'event-sourcing.query_bus' => ['enabled' => true],
            'event-sourcing.event_bus' => ['enabled' => true],
        ]);

        self::assertInstanceOf(InstantRetryCommandBus::class, $this->app->get(CommandBus::class));
        self::assertInstanceOf(SyncQueryBus::class, $this->app->get(QueryBus::class));
        self::assertInstanceOf(DefaultEventBus::class, $this->app->get(EventBus::class));
    }

    public function testAggregateHandlersAreRegisteredOnTheLaravelBus(): void
    {
        $this->useIlluminateCommandBus();

        self::assertTrue(Bus::hasCommandHandler(new CreateProfile(new CustomId('1'))));
    }

    public function testAggregateHandlerRegistrationCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.command_bus.type' => 'illuminate',
            'event-sourcing.command_bus.register_aggregate_handlers' => false,
        ]);

        self::assertFalse(Bus::hasCommandHandler(new CreateProfile(new CustomId('1'))));
    }

    public function testCommandIsHandledByTheAggregate(): void
    {
        $this->useIlluminateCommandBus();

        $id = new CustomId('1');

        $this->app->get(CommandBus::class)->dispatch(new CreateProfile($id));

        $profile = $this->app->get(RepositoryManager::class)->get(Profile::class)->load($id);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertEquals($id, $profile->aggregateRootId());
    }

    public function testUnknownCommandThrowsHandlerNotFound(): void
    {
        $this->useIlluminateCommandBus();

        $this->expectException(HandlerNotFound::class);

        $this->app->get(CommandBus::class)->dispatch(new QueryFoo('bar'));
    }

    public function testQueryIsAnsweredBySubscriber(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.query_bus.type' => 'illuminate',
            'event-sourcing.subscribers' => [ProfileProjector::class],
        ]);

        $queryBus = $this->app->get(QueryBus::class);

        self::assertInstanceOf(IlluminateQueryBus::class, $queryBus);
        self::assertSame('bar', $queryBus->dispatch(new QueryFoo('bar')));
    }

    public function testUnknownQueryThrows(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.query_bus.type' => 'illuminate',
            'event-sourcing.subscribers' => [],
        ]);

        $this->expectException(InvalidQueryHandler::class);

        $this->app->get(QueryBus::class)->dispatch(new QueryFoo('bar'));
    }

    public function testEventBusCallsListenerWithEventAndMessage(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.event_bus.enabled' => true,
            'event-sourcing.event_bus.type' => 'illuminate',
        ]);

        $received = [];

        Event::listen(
            ProfileCreated::class,
            static function (ProfileCreated $event, Message $message) use (&$received): void {
                $received[] = [$event, $message];
            },
        );

        $eventBus = $this->app->get(EventBus::class);
        $message = Message::create(new ProfileCreated(new CustomId('1')));

        self::assertInstanceOf(IlluminateEventBus::class, $eventBus);

        $eventBus->dispatch($message);

        self::assertCount(1, $received);
        self::assertSame($message->event(), $received[0][0]);
        self::assertSame($message, $received[0][1]);
    }

    public function testQueuedEventBusPushesAllMessagesAtOnce(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.event_bus.enabled' => true,
            'event-sourcing.event_bus.type' => 'illuminate',
            'event-sourcing.event_bus.queue' => [
                'enabled' => true,
                'connection' => null,
                'queue' => 'events',
            ],
        ]);

        Queue::fake();

        $eventBus = $this->app->get(EventBus::class);

        self::assertInstanceOf(QueueEventBus::class, $eventBus);

        $eventBus->dispatch(
            Message::create(new ProfileCreated(new CustomId('1'))),
            Message::create(new ProfileCreated(new CustomId('2'))),
        );

        Queue::assertPushedOn('events', DispatchMessageJob::class);
        Queue::assertPushed(DispatchMessageJob::class, 2);
    }

    public function testQueuedEventBusUsesASingleQueueRoundTrip(): void
    {
        $queue = $this->createMock(Queueing::class);
        $queue->expects(self::once())
            ->method('bulk')
            ->with(
                self::callback(
                    static fn (array $jobs) => count($jobs) === 2
                        && $jobs[0] instanceof DispatchMessageJob
                        && $jobs[1] instanceof DispatchMessageJob,
                ),
                '',
                'events',
            );

        $factory = $this->createMock(QueueFactory::class);
        $factory->expects(self::once())
            ->method('connection')
            ->with('redis')
            ->willReturn($queue);

        (new QueueEventBus($factory, 'redis', 'events'))->dispatch(
            Message::create(new ProfileCreated(new CustomId('1'))),
            Message::create(new ProfileCreated(new CustomId('2'))),
        );
    }

    public function testQueuedEventBusIgnoresEmptyDispatch(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.event_bus.enabled' => true,
            'event-sourcing.event_bus.type' => 'illuminate',
            'event-sourcing.event_bus.queue' => [
                'enabled' => true,
                'connection' => null,
                'queue' => null,
            ],
        ]);

        Queue::fake();

        $this->app->get(EventBus::class)->dispatch();

        Queue::assertNothingPushed();
    }

    public function testQueuedMessageIsHandedToTheSynchronousEventBus(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.event_bus.enabled' => true,
            'event-sourcing.event_bus.type' => 'illuminate',
            'event-sourcing.event_bus.queue' => ['enabled' => true, 'connection' => null, 'queue' => null],
        ]);

        $received = [];

        Event::listen(
            ProfileCreated::class,
            static function (ProfileCreated $event) use (&$received): void {
                $received[] = $event;
            },
        );

        $message = Message::create(new ProfileCreated(new CustomId('1')));

        (new DispatchMessageJob($message))->handle($this->app->get(IlluminateEventBus::class));

        self::assertCount(1, $received);
        self::assertSame($message->event(), $received[0]);
    }

    public function testCustomTypeNeedsAService(): void
    {
        $this->setConfig('event-sourcing.command_bus.type', 'custom');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "command_bus.service" option is required for the custom type.');

        $this->app->get(CommandBus::class);
    }

    public function testCustomServiceIsUsed(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.command_bus.type' => 'custom',
            'event-sourcing.command_bus.service' => CustomCommandBus::class,
        ]);

        $customBus = new CustomCommandBus();
        $this->app->instance(CustomCommandBus::class, $customBus);

        $command = new CreateProfile(new CustomId('1'));

        $this->app->get(CommandBus::class)->dispatch($command);

        self::assertSame([$command], $customBus->commands);
    }

    public function testCustomServiceHasToImplementTheBus(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.command_bus.type' => 'custom',
            'event-sourcing.command_bus.service' => ProfileProjector::class,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of');

        $this->app->get(CommandBus::class);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->setConfig('event-sourcing.query_bus.type', 'messenger');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query bus type "messenger" is not supported.');

        $this->app->get(QueryBus::class);
    }

    private function useIlluminateCommandBus(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.command_bus.type' => 'illuminate',
            'event-sourcing.store.type' => 'in_memory',
            'event-sourcing.subscription.store.type' => 'in_memory',
        ]);
    }
}
