<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Cryptography;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Patchlevel\Hydrator\Extension\Cryptography\Cipher\CipherKey;
use Patchlevel\Hydrator\Extension\Cryptography\Store\CipherKeyNotExists;
use Patchlevel\Hydrator\Extension\Cryptography\Store\CipherKeyStore;

use function base64_decode;
use function base64_encode;

/**
 * @phpstan-type Row = object{
 *     id: non-empty-string,
 *     subject_id: non-empty-string,
 *     crypto_key: non-empty-string,
 *     crypto_method: non-empty-string,
 *     created_at: string
 * }
 */
final class ExtensionIlluminateCipherKeyStore implements CipherKeyStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'cryptography_keys',
    ) {
    }

    public function get(string $id): CipherKey
    {
        /** @var Row|null $result */
        $result = $this->connection->table($this->tableName)
            ->select('*')
            ->where('id', '=', $id)
            ->first();

        if ($result === null) {
            throw CipherKeyNotExists::forKeyId($id);
        }

        return $this->createCipherKey($result);
    }

    public function currentKeyFor(string $subjectId): CipherKey
    {
        /** @var Row|null $result */
        $result = $this->connection->table($this->tableName)
            ->select('*')
            ->where('subject_id', '=', $subjectId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($result === null) {
            throw CipherKeyNotExists::forSubjectId($subjectId);
        }

        return $this->createCipherKey($result);
    }

    public function store(CipherKey $key): void
    {
        $this->connection->table($this->tableName)->insert(
            [
                'id' => $key->id,
                'subject_id' => $key->subjectId,
                'crypto_key' => base64_encode($key->key),
                'crypto_method' => $key->method,
                'created_at' => $this->formatCreatedAt($key->createdAt),
            ],
        );
    }

    public function remove(string $id): void
    {
        $this->connection->table($this->tableName)
            ->where('id', '=', $id)
            ->delete();
    }

    public function removeWithSubjectId(string $subjectId): void
    {
        $this->connection->table($this->tableName)
            ->where('subject_id', '=', $subjectId)
            ->delete();
    }

    /** @param Row $row */
    private function createCipherKey(object $row): CipherKey
    {
        /** @var non-empty-string $key */
        $key = base64_decode($row->crypto_key);

        return new CipherKey(
            $row->id,
            $row->subject_id,
            $key,
            $row->crypto_method,
            new DateTimeImmutable($row->created_at),
        );
    }

    /**
     * Illuminate formats dates without their offset. PostgreSQL stores the column as timestamptz,
     * so the offset is passed along to keep the point in time, like the doctrine dbal store does.
     */
    private function formatCreatedAt(DateTimeImmutable $createdAt): DateTimeImmutable|string
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            return $createdAt->format('Y-m-d H:i:sO');
        }

        return $createdAt;
    }
}
