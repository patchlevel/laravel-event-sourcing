<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Cryptography;

use Patchlevel\Hydrator\Cryptography\Cipher\CipherKey;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyNotExists;
use Patchlevel\LaravelEventSourcing\Cryptography\IlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class IlluminateCipherKeyStoreTest extends IntegrationTestCase
{
    private const TABLE_NAME = 'crypto_keys';

    private IlluminateCipherKeyStore $store;

    public function setUp(): void
    {
        parent::setUp();

        $this->store = new IlluminateCipherKeyStore($this->connection, self::TABLE_NAME);
    }

    public function testStoreAndGet(): void
    {
        $key = new CipherKey('the-key', 'aes256', 'the-iv');

        $this->store->store('foo', $key);
        $this->store->clear();

        $loaded = $this->store->get('foo');

        self::assertSame($key->key, $loaded->key);
        self::assertSame($key->method, $loaded->method);
        self::assertSame($key->iv, $loaded->iv);
    }

    public function testGetUnknownKey(): void
    {
        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('foo');
    }

    public function testRemove(): void
    {
        $this->store->store('foo', new CipherKey('the-key', 'aes256', 'the-iv'));
        $this->store->remove('foo');

        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('foo');
    }

    public function testUsesTheTableFromTheMigration(): void
    {
        // the shipped migration must create the table the configured store points at
        $this->store->store('foo', new CipherKey('the-key', 'aes256', 'the-iv'));

        self::assertSame(1, $this->connection->table(self::TABLE_NAME)->count());
    }
}
