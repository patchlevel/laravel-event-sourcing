<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Cleanup;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Patchlevel\EventSourcing\Subscription\Cleanup\CleanupTaskNotSupported;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\Illuminate\ConnectionNameNotSupported;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\Illuminate\DropIndexTask;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\Illuminate\DropTableTask;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\Illuminate\IlluminateCleanupTaskHandler;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use stdClass;

use function array_map;
use function strtolower;

#[CoversNothing]
final class IlluminateCleanupTaskHandlerTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->schema()->dropIfExists('projection');
        $this->schema()->create('projection', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->index('name', 'projection_name_idx');
        });
    }

    public function testDropTable(): void
    {
        (new IlluminateCleanupTaskHandler($this->connection))(new DropTableTask('projection'));

        self::assertFalse($this->schema()->hasTable('projection'));
    }

    public function testDropUnknownTable(): void
    {
        (new IlluminateCleanupTaskHandler($this->connection))(new DropTableTask('unknown'));

        self::assertTrue($this->schema()->hasTable('projection'));
    }

    public function testDropIndex(): void
    {
        (new IlluminateCleanupTaskHandler($this->connection))(new DropIndexTask('projection_name_idx', 'projection'));

        self::assertNotContains('projection_name_idx', $this->indexes('projection'));
        self::assertTrue($this->schema()->hasTable('projection'));
    }

    public function testDropIndexIgnoresTheCase(): void
    {
        (new IlluminateCleanupTaskHandler($this->connection))(new DropIndexTask('PROJECTION_NAME_IDX', 'projection'));

        self::assertNotContains('projection_name_idx', $this->indexes('projection'));
    }

    public function testDropUnknownIndex(): void
    {
        (new IlluminateCleanupTaskHandler($this->connection))(new DropIndexTask('unknown_idx', 'projection'));

        self::assertContains('projection_name_idx', $this->indexes('projection'));
    }

    public function testDropIndexOfUnknownTable(): void
    {
        (new IlluminateCleanupTaskHandler($this->connection))(new DropIndexTask('projection_name_idx', 'unknown'));

        self::assertContains('projection_name_idx', $this->indexes('projection'));
    }

    public function testConnectionNameWithResolver(): void
    {
        $handler = new IlluminateCleanupTaskHandler($this->app['db']);

        $handler(new DropTableTask('projection', $this->connection->getName()));

        self::assertFalse($this->schema()->hasTable('projection'));
    }

    public function testConnectionNameWithoutResolver(): void
    {
        $this->expectException(ConnectionNameNotSupported::class);

        (new IlluminateCleanupTaskHandler($this->connection))(new DropTableTask('projection', 'other'));
    }

    public function testSupports(): void
    {
        $handler = new IlluminateCleanupTaskHandler($this->connection);

        self::assertTrue($handler->supports(new DropTableTask('projection')));
        self::assertTrue($handler->supports(new DropIndexTask('projection_name_idx', 'projection')));
        self::assertFalse($handler->supports(new stdClass()));
    }

    public function testUnsupportedTask(): void
    {
        $this->expectException(CleanupTaskNotSupported::class);

        (new IlluminateCleanupTaskHandler($this->connection))(new stdClass());
    }

    private function schema(): Builder
    {
        return $this->connection->getSchemaBuilder();
    }

    /** @return list<string> */
    private function indexes(string $table): array
    {
        return array_map(strtolower(...), $this->schema()->getIndexListing($table));
    }
}
