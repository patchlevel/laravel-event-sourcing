<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Cryptography;

use Illuminate\Database\Connection;
use Patchlevel\Hydrator\Cryptography\Cipher\CipherKey;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyNotExists;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyStore;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function sprintf;

/**
 * @phpstan-type Row = object{
 *     subject_id: non-empty-string,
 *     crypto_key: non-empty-string,
 *     crypto_method: non-empty-string,
 *     crypto_iv: non-empty-string
 * }
 */
final class IlluminateCipherKeyStore implements CipherKeyStore
{
    /** @var array<string, CipherKey> */
    private array $keyCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'crypto_keys',
    ) {
    }

    public function get(string $id): CipherKey
    {
        if (array_key_exists($id, $this->keyCache)) {
            return $this->keyCache[$id];
        }

        /** @var Row|null $result */
        $result = $this->connection->selectOne(
            sprintf('SELECT * FROM %s WHERE subject_id = :subject_id', $this->tableName),
            ['subject_id' => $id],
        );

        if ($result === null) {
            throw new CipherKeyNotExists($id);
        }

        $this->keyCache[$id] = new CipherKey(
            base64_decode($result->crypto_key),
            $result->crypto_method,
            base64_decode($result->crypto_iv),
        );

        return $this->keyCache[$id];
    }

    public function store(string $id, CipherKey $key): void
    {
        $this->connection->statement(
            <<<SQL
            INSERT INTO {$this->tableName} 
                (subject_id, crypto_key, crypto_method, crypto_iv) 
            VALUES 
                (:subject_id, :crypto_key, :crypto_method, :crypto_iv)
SQL,
            [
                'subject_id' => $id,
                'crypto_key' => base64_encode($key->key),
                'crypto_method' => $key->method,
                'crypto_iv' => base64_encode($key->iv),
            ],
        );

        $this->keyCache[$id] = $key;
    }

    public function remove(string $id): void
    {
        $this->connection->statement(
            <<<SQL
DELETE FROM {$this->tableName} WHERE subject_id = :subject_id
SQL,
            ['subject_id' => $id],
        );

        unset($this->keyCache[$id]);
    }

    public function clear(): void
    {
        $this->keyCache = [];
    }
}
