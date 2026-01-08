<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Patchlevel\EventSourcing\Aggregate\CustomId;
use Patchlevel\EventSourcing\Repository\Repository;
use Patchlevel\LaravelEventSourcing\Facade\CommandBus;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\Profile;

final class CommandBusTest extends TestCase
{
    public function testRepositoryAvailable(): void
    {
        $id = CustomId::fromString('1');

        CommandBus::dispatch(new CreateProfile($id));

        $profile = Profile::repository()->load($id);
        self::assertEquals($id, $profile->aggregateRootId());
    }
}
