<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Store;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException as IlluminateUniqueConstraintViolationException;
use Patchlevel\EventSourcing\Clock\SystemClock;
use Patchlevel\EventSourcing\Message\HeaderNotFound;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\EventSourcing\Message\Serializer\DefaultHeadersSerializer;
use Patchlevel\EventSourcing\Message\Serializer\HeadersSerializer;
use Patchlevel\EventSourcing\Serializer\EventSerializer;
use Patchlevel\EventSourcing\Store\ArchivedHeader;
use Patchlevel\EventSourcing\Store\Criteria\ArchivedCriterion;
use Patchlevel\EventSourcing\Store\Criteria\Criteria;
use Patchlevel\EventSourcing\Store\Criteria\EventIdCriterion;
use Patchlevel\EventSourcing\Store\Criteria\EventsCriterion;
use Patchlevel\EventSourcing\Store\Criteria\FromIndexCriterion;
use Patchlevel\EventSourcing\Store\Criteria\FromPlayheadCriterion;
use Patchlevel\EventSourcing\Store\Criteria\StreamCriterion;
use Patchlevel\EventSourcing\Store\Criteria\ToIndexCriterion;
use Patchlevel\EventSourcing\Store\Criteria\ToPlayheadCriterion;
use Patchlevel\EventSourcing\Store\Header\EventIdHeader;
use Patchlevel\EventSourcing\Store\Header\IndexHeader;
use Patchlevel\EventSourcing\Store\Header\PlayheadHeader;
use Patchlevel\EventSourcing\Store\Header\RecordedOnHeader;
use Patchlevel\EventSourcing\Store\Header\StreamNameHeader;
use Patchlevel\EventSourcing\Store\LockCouldNotBeAcquired;
use Patchlevel\EventSourcing\Store\LockCouldNotBeFreed;
use Patchlevel\EventSourcing\Store\LockingNotImplemented;
use Patchlevel\EventSourcing\Store\MissingDataForStorage;
use Patchlevel\EventSourcing\Store\Stream;
use Patchlevel\EventSourcing\Store\StreamStore;
use Patchlevel\EventSourcing\Store\SubscriptionStore;
use Patchlevel\EventSourcing\Store\UniqueConstraintViolation;
use Patchlevel\EventSourcing\Store\UnsupportedCriterion;
use PDO;
use Pdo\Pgsql;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use stdClass;

use function array_filter;
use function array_values;
use function class_exists;
use function count;
use function current;
use function explode;
use function floor;
use function in_array;
use function sprintf;
use function str_contains;
use function str_replace;

use const PHP_VERSION_ID;

/**
 * @phpstan-type Row = array{
 *      stream: string,
 *      playhead: int|null,
 *      event_id: string,
 *      event_name: string,
 *      event_payload: string,
 *      recorded_on: DateTimeImmutable,
 *      archived: bool,
 *      custom_headers: string,
 *      id?: int
 *  }
 */
final class StreamIlluminateStore implements StreamStore, SubscriptionStore
{
    /**
     * PostgreSQL has a limit of 65535 parameters in a single query.
     */
    private const MAX_UNSIGNED_SMALL_INT = 65_535;

    /**
     * Default lock id for advisory lock.
     */
    private const DEFAULT_LOCK_ID = 133742;

    /**
     * MariaDB does not support an infinite (negative) lock timeout. Very large values such as
     * PHP_INT_MAX overflow its internal timeout arithmetic and make GET_LOCK return NULL. We
     * therefore use a large but safe value (INT32_MAX minus a small buffer) as "effectively
     * infinite" wait.
     */
    private const INFINITE_MARIADB_LOCK_TIMEOUT = 2_147_482_647;

    private readonly HeadersSerializer $headersSerializer;

    private readonly ClockInterface $clock;

    /** @var array{table_name: string, locking: bool, lock_id: int, lock_timeout: int, keep_index: bool} */
    private readonly array $config;

    private bool $hasLock = false;

    /** @param array{table_name?: string, locking?: bool, lock_id?: int, lock_timeout?: int, keep_index?: bool} $config */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventSerializer $eventSerializer,
        HeadersSerializer|null $headersSerializer = null,
        ClockInterface|null $clock = null,
        array $config = [],
    ) {
        $this->headersSerializer = $headersSerializer ?? DefaultHeadersSerializer::createDefault();
        $this->clock = $clock ?? new SystemClock();

        $this->config = [
            'table_name' => 'event_store',
            'locking' => true,
            'lock_id' => self::DEFAULT_LOCK_ID,
            'lock_timeout' => -1,
            'keep_index' => false,
            ...$config,
        ];
    }

    public function load(
        Criteria|null $criteria = null,
        int|null $limit = null,
        int|null $offset = null,
        bool $backwards = false,
    ): Stream {
        $builder = $this->connection
            ->table($this->config['table_name'])
            ->select('*')
            ->orderBy('id', $backwards ? 'desc' : 'asc');

        $this->applyCriteria($builder, $criteria ?? new Criteria());

        if ($limit !== null) {
            $builder->limit($limit);
        }

        if ($offset !== null) {
            $builder->offset($offset);
        }

        return new StreamIlluminateStoreStream(
            $builder->cursor(),
            $this->eventSerializer,
            $this->headersSerializer,
        );
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function count(Criteria|null $criteria = null): int
    {
        $builder = $this->connection
            ->table($this->config['table_name']);

        $this->applyCriteria($builder, $criteria ?? new Criteria());

        return $builder->count();
    }

    public function save(Message ...$messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->transactional(function () use ($messages): void {
            $columnsLength = $this->config['keep_index'] ? 9 : 8;
            $batchSize = (int)floor(self::MAX_UNSIGNED_SMALL_INT / $columnsLength);

            $rows = [];
            foreach ($messages as $message) {
                $data = $this->eventSerializer->serialize($message->event());

                try {
                    $streamName = $message->header(StreamNameHeader::class)->streamName;
                } catch (HeaderNotFound $e) {
                    throw new MissingDataForStorage($e->name, $e);
                }

                $eventId = $message->hasHeader(EventIdHeader::class)
                    ? $message->header(EventIdHeader::class)->eventId
                    : Uuid::uuid7()->toString();

                $row = [
                    'stream' => $streamName,
                    'playhead' => $message->hasHeader(PlayheadHeader::class)
                        ? $message->header(PlayheadHeader::class)->playhead
                        : null,
                    'event_id' => $eventId,
                    'event_name' => $data->name,
                    'event_payload' => $data->payload,
                    'recorded_on' => $message->hasHeader(RecordedOnHeader::class)
                        ? $message->header(RecordedOnHeader::class)->recordedOn
                        : $this->clock->now(),
                    'archived' => $message->hasHeader(ArchivedHeader::class),
                    'custom_headers' => $this->headersSerializer->serialize($this->getCustomHeaders($message)),
                ];

                if ($this->config['keep_index']) {
                    try {
                        $row['id'] = $message->header(IndexHeader::class)->index;
                    } catch (HeaderNotFound $e) {
                        throw new MissingDataForStorage($e->name, $e);
                    }
                }

                $rows[] = $row;

                if (count($rows) !== $batchSize) {
                    continue;
                }

                $this->executeSave($rows);
                $rows = [];
            }

            if ($rows !== []) {
                $this->executeSave($rows);
            }

            if (!$this->config['keep_index'] || $this->driverName() !== 'pgsql') {
                return;
            }

            $this->connection->statement(sprintf(
                "SELECT setval('%s', (SELECT MAX(id) FROM %s));",
                sprintf('%s_id_seq', $this->config['table_name']),
                $this->config['table_name'],
            ));
        });
    }

    public function transactional(Closure $function): void
    {
        if ($this->hasLock || !$this->config['locking']) {
            $this->connection->transaction($function);

            return;
        }

        $this->connection->transaction(function () use ($function): void {
            $this->lock();

            try {
                $function();
            } finally {
                $this->unlock();
            }
        });
    }

    /** @return list<string> */
    public function streams(): array
    {
        /** @var list<string> $streams */
        $streams = $this->connection
            ->table($this->config['table_name'])
            ->select('stream')
            ->distinct()
            ->orderBy('stream')
            ->pluck('stream')
            ->all();

        return $streams;
    }

    public function remove(Criteria|null $criteria = null): void
    {
        $builder = $this->connection->table($this->config['table_name']);

        $this->applyCriteria($builder, $criteria ?? new Criteria());

        $builder->delete();
    }

    public function archive(Criteria|null $criteria = null): void
    {
        $builder = $this->connection->table($this->config['table_name']);

        $this->applyCriteria($builder, $criteria ?? new Criteria());

        $builder->update(['archived' => true]);
    }

    public function supportSubscription(): bool
    {
        return $this->driverName() === 'pgsql' && class_exists(PDO::class);
    }

    public function setupSubscription(): void
    {
        if (!$this->supportSubscription()) {
            return;
        }

        $functionName = $this->createTriggerFunctionName();

        $this->connection->statement(sprintf(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION %1$s() RETURNS TRIGGER AS $$
                    BEGIN
                        PERFORM pg_notify('%2$s', NEW.stream::text);
                        RETURN NEW;
                    END;
                $$ LANGUAGE plpgsql;
                SQL,
            $functionName,
            $this->config['table_name'],
        ));

        $this->connection->statement(sprintf(
            'DROP TRIGGER IF EXISTS notify_trigger ON %s;',
            $this->config['table_name'],
        ));

        $this->connection->statement(sprintf(
            'CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON %1$s FOR EACH ROW EXECUTE PROCEDURE %2$s();',
            $this->config['table_name'],
            $functionName,
        ));
    }

    public function wait(int $timeoutMilliseconds): void
    {
        if (!$this->supportSubscription()) {
            return;
        }

        $this->connection->statement(sprintf('LISTEN "%s"', $this->config['table_name']));

        if (PHP_VERSION_ID >= 80400) {
            /** @var Pgsql $nativeConnection */
            $nativeConnection = $this->connection->getPdo();
            $nativeConnection->getNotify(PDO::FETCH_ASSOC, $timeoutMilliseconds);

            return;
        }

        /** @var PDO $nativeConnection */
        $nativeConnection = $this->connection->getPdo();
        $nativeConnection->pgsqlGetNotify(PDO::FETCH_ASSOC, $timeoutMilliseconds);
    }

    /** @return list<object> */
    private function getCustomHeaders(Message $message): array
    {
        $filteredHeaders = [
            IndexHeader::class,
            StreamNameHeader::class,
            EventIdHeader::class,
            PlayheadHeader::class,
            RecordedOnHeader::class,
            ArchivedHeader::class,
        ];

        return array_values(
            array_filter(
                $message->headers(),
                static fn (object $header): bool => !in_array($header::class, $filteredHeaders, true),
            ),
        );
    }

    private function applyCriteria(Builder $builder, Criteria $criteria): void
    {
        foreach ($criteria->all() as $criterion) {
            switch ($criterion::class) {
                case StreamCriterion::class:
                    if ($criterion->all()) {
                        break;
                    }

                    if ($criterion->streamName === []) {
                        break;
                    }

                    $builder->where(static function (Builder $query) use ($criterion): void {
                        foreach ($criterion->streamName as $streamName) {
                            if (str_contains($streamName, '*')) {
                                $query->orWhere('stream', 'LIKE', str_replace('*', '%', $streamName));
                            } else {
                                $query->orWhere('stream', '=', $streamName);
                            }
                        }
                    });

                    break;
                case FromPlayheadCriterion::class:
                    $builder->where('playhead', '>', $criterion->fromPlayhead);
                    break;
                case ToPlayheadCriterion::class:
                    $builder->where('playhead', '<', $criterion->toPlayhead);
                    break;
                case ArchivedCriterion::class:
                    $builder->where('archived', '=', $criterion->archived);
                    break;
                case FromIndexCriterion::class:
                    $builder->where('id', '>', $criterion->fromIndex);
                    break;
                case ToIndexCriterion::class:
                    $builder->where('id', '<', $criterion->toIndex);
                    break;
                case EventsCriterion::class:
                    $builder->whereIn('event_name', $criterion->events);
                    break;
                case EventIdCriterion::class:
                    $builder->where('event_id', '=', $criterion->eventId);
                    break;
                default:
                    throw new UnsupportedCriterion($criterion::class);
            }
        }
    }

    /** @param list<Row> $rows */
    private function executeSave(array $rows): void
    {
        try {
            $this->connection->table($this->config['table_name'])->insert($rows);
        } catch (IlluminateUniqueConstraintViolationException $e) {
            throw new UniqueConstraintViolation($e);
        }
    }

    private function lock(): void
    {
        $this->hasLock = true;

        $driver = $this->driverName();

        if ($driver === 'pgsql') {
            $this->connection->select('SELECT pg_advisory_xact_lock(?)', [$this->config['lock_id']]);

            return;
        }

        if ($driver === 'mariadb' || $driver === 'mysql') {
            $lockTimeout = $this->config['lock_timeout'];

            if ($lockTimeout < 0 && $this->isMariaDb()) {
                $lockTimeout = self::INFINITE_MARIADB_LOCK_TIMEOUT;
            }

            $result = $this->fetchLockResult(
                'SELECT GET_LOCK(?, ?)',
                [
                    (string)$this->config['lock_id'],
                    $lockTimeout,
                ],
            );

            if ($result === 0) {
                throw LockCouldNotBeAcquired::byTimeout($this->config['lock_id'], $this->config['lock_timeout']);
            }

            if ($result !== 1) {
                throw LockCouldNotBeAcquired::byError($this->config['lock_id']);
            }

            return;
        }

        if ($driver === 'sqlite') {
            return; // sql locking is not needed because of file locking
        }

        throw new LockingNotImplemented($this->connection::class);
    }

    private function unlock(): void
    {
        $this->hasLock = false;

        $driver = $this->driverName();

        if ($driver === 'pgsql') {
            return; // lock is released automatically after transaction
        }

        if ($driver === 'mariadb' || $driver === 'mysql') {
            $result = $this->fetchLockResult(
                'SELECT RELEASE_LOCK(?)',
                [(string)$this->config['lock_id']],
            );

            if ($result === 0) {
                throw LockCouldNotBeFreed::notOurs($this->config['lock_id']);
            }

            if ($result !== 1) {
                throw LockCouldNotBeFreed::notExist($this->config['lock_id']);
            }

            return;
        }

        if ($driver === 'sqlite') {
            return; // sql locking is not needed because of file locking
        }

        throw new LockingNotImplemented($this->connection::class);
    }

    /**
     * GET_LOCK and RELEASE_LOCK return 1 on success, 0 on timeout / foreign lock
     * and NULL on error. The driver may hand the value back as int or string.
     *
     * @param list<mixed> $bindings
     */
    private function fetchLockResult(string $query, array $bindings): int|null
    {
        $row = $this->connection->selectOne($query, $bindings);

        if (!$row instanceof stdClass) {
            return null;
        }

        $value = current((array)$row);

        if ($value === null || $value === false) {
            return null;
        }

        return (int)$value;
    }

    private function createTriggerFunctionName(): string
    {
        $tableConfig = explode('.', $this->config['table_name']);

        if (count($tableConfig) === 1) {
            return sprintf('notify_%s', $tableConfig[0]);
        }

        return sprintf('%s.notify_%s', $tableConfig[0], $tableConfig[1]);
    }

    private function driverName(): string
    {
        return $this->connection->getDriverName();
    }

    /**
     * A MariaDB server is commonly reached through the "mysql" driver, so the driver name alone
     * is not enough to tell both apart. Illuminate detects it by the reported server version.
     */
    private function isMariaDb(): bool
    {
        if ($this->driverName() === 'mariadb') {
            return true;
        }

        return $this->connection instanceof MySqlConnection && $this->connection->isMaria();
    }
}
