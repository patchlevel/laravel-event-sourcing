<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\CommandBus\CommandBus;

final class CustomCommandBus implements CommandBus
{
    /** @var list<object> */
    public array $commands = [];

    public function dispatch(object $command): void
    {
        $this->commands[] = $command;
    }
}
