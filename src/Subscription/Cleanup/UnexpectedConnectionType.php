<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Subscription\Cleanup;

use RuntimeException;

use function sprintf;

final class UnexpectedConnectionType extends RuntimeException
{
    /**
     * @param class-string $expected
     * @param class-string $actual
     */
    public function __construct(string|null $connectionName, string $expected, string $actual)
    {
        parent::__construct(
            sprintf(
                'Expected connection "%s" to be of type "%s", got "%s"',
                $connectionName ?? 'default (null)',
                $expected,
                $actual,
            ),
        );
    }
}
