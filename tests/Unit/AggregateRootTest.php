<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Patchlevel\EventSourcing\Aggregate\CustomId;
use Patchlevel\EventSourcing\Repository\Repository;
use Patchlevel\Hydrator\Hydrator;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\Profile;

final class AggregateRootTest extends TestCase
{
    public function testRepositoryAvailable(): void
    {
        $profileRepository = Profile::repository();
        self::assertInstanceOf(Repository::class, $profileRepository);
    }

    public function testRepositoryAvailableAndAggregateCanBeSaved(): void
    {
        $profile = Profile::create(new CreateProfile(CustomId::fromString('1')), $this->createMock(Hydrator::class));
        $profile->save();
        self::assertTrue(true);
    }

    public function testRepositoryAvailableAndAggregateCanBeLoaded(): void
    {
        $profile = Profile::create(new CreateProfile(CustomId::fromString('1')), $this->createMock(Hydrator::class));
        $profile->save();

        $profile2 = Profile::load(CustomId::fromString('1'));
        self::assertNotSame($profile, $profile2);
        self::assertEquals($profile->aggregateRootId(), $profile2->aggregateRootId());
        self::assertEquals($profile, $profile2);
    }

    public function testRepositoryAvailableAndAggregateCanBeChecked(): void
    {
        $profile = Profile::create(new CreateProfile(CustomId::fromString('1')), $this->createMock(Hydrator::class));
        $profile->save();

        self::assertFalse(Profile::has(CustomId::fromString('2')));
        self::assertTrue(Profile::has(CustomId::fromString('1')));
    }
}
