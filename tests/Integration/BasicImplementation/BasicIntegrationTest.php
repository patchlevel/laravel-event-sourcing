<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation;

use Patchlevel\EventSourcing\Message\Serializer\DefaultHeadersSerializer;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootRegistry;
use Patchlevel\EventSourcing\Repository\DefaultRepositoryManager;
use Patchlevel\EventSourcing\Serializer\DefaultEventSerializer;
use Patchlevel\EventSourcing\Subscription\Engine\DefaultSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\ThrowOnErrorSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Repository\RunSubscriptionEngineRepositoryManager;
use Patchlevel\EventSourcing\Subscription\Store\InMemorySubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Subscriber\MetadataSubscriberAccessorRepository;
use Patchlevel\LaravelEventSourcing\Store\StreamIlluminateStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Processor\SendEmailProcessor;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Projection\ProfileProjector;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class BasicIntegrationTest extends IntegrationTestCase
{
    public function testSuccessful(): void
    {
        $store = new StreamIlluminateStore(
            $this->connection,
            DefaultEventSerializer::createFromPaths([__DIR__ . '/Events']),
        );

        $profileProjector = new ProfileProjector($this->connection);

        $engine = new ThrowOnErrorSubscriptionEngine(new DefaultSubscriptionEngine(
            $store,
            new InMemorySubscriptionStore(),
            new MetadataSubscriberAccessorRepository([
                $profileProjector,
                new SendEmailProcessor(),
            ]),
        ));

        $manager = new RunSubscriptionEngineRepositoryManager(
            new DefaultRepositoryManager(
                new AggregateRootRegistry(['profile' => Profile::class]),
                $store,
            ),
            $engine,
        );

        $engine->setup(skipBooting: true);

        $profileId = ProfileId::generate();
        $profile = Profile::create($profileId, 'John');

        $repository = $manager->get(Profile::class);
        $repository->save($profile);

        $result = $this->connection->selectOne(
            'SELECT * FROM projection_profile WHERE id = ?',
            [$profileId->toString()],
        );

        self::assertIsObject($result);
        self::assertObjectHasProperty('id', $result);
        self::assertSame($profileId->toString(), $result->id);
        self::assertSame('John', $result->name);

        $repository = $manager->get(Profile::class);
        $profile = $repository->load($profileId);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertEquals($profileId, $profile->aggregateRootId());
        self::assertSame(1, $profile->playhead());
        self::assertSame('John', $profile->name());
        self::assertSame(1, SendEmailMock::count());
    }
}
