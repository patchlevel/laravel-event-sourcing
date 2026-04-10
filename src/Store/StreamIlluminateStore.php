<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Store;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
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
use Patchlevel\EventSourcing\Store\LockingNotImplemented;
use Patchlevel\EventSourcing\Store\MissingDataForStorage;
use Patchlevel\EventSourcing\Store\Stream;
use Patchlevel\EventSourcing\Store\StreamStore;
use Patchlevel\EventSourcing\Store\SubscriptionStore;
use Patchlevel\EventSourcing\Store\UniqueConstraintViolation;
use Patchlevel\EventSourcing\Store\UnsupportedCriterion;
use PDO;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

use function array_filter;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function floor;
use function in_array;
use function method_exists;
use function sprintf;
use function str_contains;
use function str_replace;

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

    private readonly HeadersSerializer $headersSerializer;

    private readonly ClockInterface $clock;

    /** @var array{table_name: string, locking: bool, lock_id: int, lock_timeout: int, keep_index: bool} */
    private readonly array $config;

    private bool $hasLock = false;

    /** @param array{table_name?: string, locking?: bool, lock_id?: int, lock_timeout?: int, keep_index?: bool} $config */
    public function __construct(
        private readonly ConnectionInterface $connection,
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
        return $this->driverName() === 'pgsql'
            && class_exists(PDO::class)
            && method_exists($this->connection, 'getPdo');
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

        if (!$this->connection instanceof Connection) {
            return;
        }

        $this->connection->getPdo()->pgsqlGetNotify(PDO::FETCH_ASSOC, $timeoutMilliseconds);
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
                        foreach ($criterion->streamName as $index => $streamName) {
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
            $this->connection->selectOne('SELECT pg_advisory_xact_lock(?)', [$this->config['lock_id']]);

            return;
        }

        if ($driver === 'mariadb' || $driver === 'mysql') {
            $this->connection->select(
                'SELECT GET_LOCK(?, ?)',
                [
                    (string)$this->config['lock_id'],
                    $this->config['lock_timeout'],
                ],
            );

            return;
        }

        if ($driver === 'sqlite') {
            return; // sql locking is not needed because of file locking
        }

        throw new LockingNotImplemented(Connection::class);
    }

    private function unlock(): void
    {
        $this->hasLock = false;

        $driver = $this->driverName();

        if ($driver === 'pgsql') {
            return; // lock is released automatically after transaction
        }

        if ($driver === 'mariadb' || $driver === 'mysql') {
            $this->connection->select(
                'SELECT RELEASE_LOCK(?)',
                [
                    (string)$this->config['lock_id'],
                ],
            );

            return;
        }

        if ($driver === 'sqlite') {
            return; // sql locking is not needed because of file locking
        }

        throw new LockingNotImplemented(Connection::class);
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
        if ($this->connection instanceof Connection) {
            return $this->connection->getDriverName();
        }

        return 'unknown';
    }
}
