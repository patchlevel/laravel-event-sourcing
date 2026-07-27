<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Subscription\Store;

use Closure;
use DateTime;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Patchlevel\EventSourcing\Clock\SystemClock;
use Patchlevel\EventSourcing\Subscription\RunMode;
use Patchlevel\EventSourcing\Subscription\Status;
use Patchlevel\EventSourcing\Subscription\Store\LockableSubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionCriteria;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionNotFound;
use Patchlevel\EventSourcing\Subscription\Store\TransactionCommitNotPossible;
use Patchlevel\EventSourcing\Subscription\Subscription;
use Patchlevel\EventSourcing\Subscription\SubscriptionError;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_map;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function serialize;
use function unserialize;

use const JSON_THROW_ON_ERROR;

/** @phpstan-type Row = object{
 *     id: string,
 *     group_name: string,
 *     run_mode: string,
 *     position: int|string,
 *     status: string,
 *     error_message: string|null,
 *     error_previous_status: string|null,
 *     error_context: string|array<string, mixed>|null,
 *     retry_attempt: int|string,
 *     last_saved_at: mixed,
 *     cleanup_tasks?: string|null,
 * }
 */
final class IlluminateSubscriptionStore implements LockableSubscriptionStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $tableName = 'subscriptions',
    ) {
    }

    public function get(string $subscriptionId): Subscription
    {
        /** @var Row|null $result */
        $result = $this->connection->table($this->tableName)
            ->select('*')
            ->where('id', '=', $subscriptionId)
            ->first();

        if ($result === null) {
            throw new SubscriptionNotFound($subscriptionId);
        }

        return $this->createSubscription($result);
    }

    /** @return list<Subscription> */
    public function find(SubscriptionCriteria|null $criteria = null): array
    {
        $qb = $this->connection->table($this->tableName)
            ->select('*')
            ->orderBy('id');

        if ($this->connection->getDriverName() !== 'sqlite') {
            $qb->lockForUpdate();
        }

        if ($criteria !== null) {
            if ($criteria->ids !== null) {
                $qb->whereIn('id', $criteria->ids);
            }

            if ($criteria->groups !== null) {
                $qb->whereIn('group_name', $criteria->groups);
            }

            if ($criteria->status !== null) {
                $qb->whereIn(
                    'status',
                    array_map(static fn (Status $status) => $status->value, $criteria->status),
                );
            }
        }

        /** @var list<Row> $result */
        $result = $qb->get()->all();

        return array_map(
            fn (object $data) => $this->createSubscription($data),
            $result,
        );
    }

    public function add(Subscription $subscription): void
    {
        $subscriptionError = $subscription->subscriptionError();

        $subscription->updateLastSavedAt($this->clock->now());

        $this->connection->statement(
            <<<SQL
            INSERT INTO {$this->tableName}
                (id, group_name, run_mode, status, position, error_message, error_previous_status, error_context, retry_attempt, last_saved_at, cleanup_tasks)
            VALUES
                (:id, :group_name, :run_mode, :status, :position, :error_message, :error_previous_status, :error_context, :retry_attempt, :last_saved_at, :cleanup_tasks)
SQL,
            [
                'id' => $subscription->id(),
                'group_name' => $subscription->group(),
                'run_mode' => $subscription->runMode()->value,
                'status' => $subscription->status()->value,
                'position' => $subscription->position(),
                'error_message' => $subscriptionError?->errorMessage,
                'error_previous_status' => $subscriptionError?->previousStatus?->value,
                'error_context' => $subscriptionError?->errorContext !== null ? json_encode($subscriptionError->errorContext, JSON_THROW_ON_ERROR) : null,
                'retry_attempt' => $subscription->retryAttempt(),
                'last_saved_at' => $subscription->lastSavedAt(),
                'cleanup_tasks' => $subscription->cleanupTasks() !== null ? serialize($subscription->cleanupTasks()) : null,
            ],
        );
    }

    public function update(Subscription $subscription): void
    {
        $subscriptionError = $subscription->subscriptionError();

        $subscription->updateLastSavedAt($this->clock->now());

        $effectedRows = $this->connection->table($this->tableName)
            ->where('id', '=', $subscription->id())
            ->update(
                [
                    'group_name' => $subscription->group(),
                    'run_mode' => $subscription->runMode()->value,
                    'status' => $subscription->status()->value,
                    'position' => $subscription->position(),
                    'error_message' => $subscriptionError?->errorMessage,
                    'error_previous_status' => $subscriptionError?->previousStatus?->value,
                    'error_context' => $subscriptionError?->errorContext !== null ? json_encode($subscriptionError->errorContext, JSON_THROW_ON_ERROR) : null,
                    'retry_attempt' => $subscription->retryAttempt(),
                    'last_saved_at' => $subscription->lastSavedAt(),
                    'cleanup_tasks' => $subscription->cleanupTasks() !== null ? serialize($subscription->cleanupTasks()) : null,
                ],
            );

        if ($effectedRows === 0) {
            throw new SubscriptionNotFound($subscription->id());
        }
    }

    public function remove(Subscription $subscription): void
    {
        $this->connection->statement(
            <<<SQL
DELETE FROM {$this->tableName} WHERE id = :id
SQL,
            ['id' => $subscription->id()],
        );
    }

    /**
     * @param Closure():T $closure
     *
     * @return T
     *
     * @throws TransactionCommitNotPossible
     *
     * @template T
     */
    public function inLock(Closure $closure): mixed
    {
        $this->connection->beginTransaction();

        try {
            return $closure();
        } finally {
            try {
                $this->connection->commit();
            } catch (Throwable $e) {
                throw new TransactionCommitNotPossible($e);
            }
        }
    }

    /** @param Row $row */
    private function createSubscription(object $row): Subscription
    {
        $context = $this->decodeErrorContext($row->error_context);

        return new Subscription(
            $row->id,
            $row->group_name,
            RunMode::from($row->run_mode),
            Status::from($row->status),
            (int)$row->position,
            $row->error_message !== null ? new SubscriptionError(
                $row->error_message,
                $row->error_previous_status !== null ? Status::from($row->error_previous_status) : Status::New,
                $context,
            ) : null,
            (int)$row->retry_attempt,
            self::normalizeDateTime($row->last_saved_at),
            isset($row->cleanup_tasks) && $row->cleanup_tasks !== null ? unserialize($row->cleanup_tasks) : null,
        );
    }

    /** @return array<string, mixed>|null */
    private function decodeErrorContext(mixed $value): array|null
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        return null;
    }

    private static function normalizeDateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTime) {
            return DateTimeImmutable::createFromMutable($value);
        }

        return new DateTimeImmutable((string)$value);
    }
}
