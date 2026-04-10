<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Subscription\Cleanup;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Subscription\Cleanup\CleanupTaskHandler;
use Patchlevel\EventSourcing\Subscription\Cleanup\CleanupTaskNotSupported;

use function strtolower;

final class IlluminateCleanupTaskHandler implements CleanupTaskHandler
{
    public function __construct(
        private readonly Connection|ConnectionResolverInterface $connection,
    ) {
    }

    public function __invoke(object $task): void
    {
        if ($task instanceof DropTableTask) {
            $schemaManager = $this->connection($task->connectionName)->getSchemaBuilder();
            if ($schemaManager->hasTable($task->table)) {
                $schemaManager->drop($task->table);
            }

            return;
        }

        if ($task instanceof DropIndexTask) {
            $schemaManager = $this->connection($task->connectionName)->getSchemaBuilder();

            if (!$schemaManager->hasTable($task->table)) {
                return;
            }

            foreach ($schemaManager->getIndexListing($task->table) as $indexName) {
                if (strtolower($indexName) === strtolower($task->index)) {
                    $schemaManager->table($task->table, static function (Blueprint $table) use ($task): void {
                        $table->dropIndex($task->index);
                    });
                    break;
                }
            }

            return;
        }

        throw new CleanupTaskNotSupported($task, self::class);
    }

    public function supports(object $task): bool
    {
        return $task instanceof DropTableTask || $task instanceof DropIndexTask;
    }

    private function connection(string|null $connectionName): Connection
    {
        if ($this->connection instanceof ConnectionResolverInterface) {
            $connection = $this->connection->connection($connectionName);

            if (!$connection instanceof Connection) {
                throw new UnexpectedConnectionType($connectionName, Connection::class, $connection::class);
            }

            return $connection;
        }

        if ($connectionName === null) {
            return $this->connection;
        }

        throw new ConnectionNameNotSupported($connectionName);
    }
}
