<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Cryptography;

use Illuminate\Database\UniqueConstraintViolationException;
use Patchlevel\Hydrator\Cryptography\Cipher\CipherKey;
use Patchlevel\Hydrator\Cryptography\Cipher\OpensslCipher;
use Patchlevel\Hydrator\Cryptography\Cipher\OpensslCipherKeyFactory;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyNotExists;
use Patchlevel\LaravelEventSourcing\Cryptography\IlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

use function str_repeat;

/**
 * There is no dedicated test for the DoctrineCipherKeyStore in patchlevel/event-sourcing,
 * so the contract here is derived from the CipherKeyStore interface and both implementations.
 */
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

    public function testGetFromAnotherInstance(): void
    {
        $key = new CipherKey('the-key', 'aes256', 'the-iv');

        $this->store->store('foo', $key);

        $loaded = (new IlluminateCipherKeyStore($this->connection, self::TABLE_NAME))->get('foo');

        self::assertEquals($key, $loaded);
    }

    public function testGetUnknownKey(): void
    {
        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('foo');
    }

    public function testGetIsCached(): void
    {
        $this->store->store('foo', new CipherKey('the-key', 'aes256', 'the-iv'));

        $this->connection->table(self::TABLE_NAME)->delete();

        self::assertSame('the-key', $this->store->get('foo')->key);

        $this->store->clear();

        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('foo');
    }

    public function testBinaryKeyRoundTrip(): void
    {
        $key = (new OpensslCipherKeyFactory())();

        $this->store->store('foo', $key);
        $this->store->clear();

        $loaded = $this->store->get('foo');

        self::assertSame($key->key, $loaded->key);
        self::assertSame($key->method, $loaded->method);
        self::assertSame($key->iv, $loaded->iv);
    }

    public function testStoredKeyDecryptsData(): void
    {
        $cipher = new OpensslCipher();
        $key = (new OpensslCipherKeyFactory())();

        $encrypted = $cipher->encrypt($key, 'john@example.com');

        $this->store->store('foo', $key);
        $this->store->clear();

        self::assertSame('john@example.com', $cipher->decrypt($this->store->get('foo'), $encrypted));
    }

    public function testStoreDuplicateSubject(): void
    {
        $this->store->store('foo', new CipherKey('first-key', 'aes256', 'first-iv'));

        $exception = null;

        try {
            $this->store->store('foo', new CipherKey('second-key', 'aes256', 'second-iv'));
        } catch (UniqueConstraintViolationException $e) {
            $exception = $e;
        }

        self::assertNotNull($exception);

        $this->store->clear();

        self::assertSame('first-key', $this->store->get('foo')->key);
    }

    public function testRemove(): void
    {
        $this->store->store('foo', new CipherKey('the-key', 'aes256', 'the-iv'));
        $this->store->remove('foo');

        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('foo');
    }

    public function testRemoveUnknownKey(): void
    {
        $this->store->remove('foo');

        self::assertSame(0, $this->connection->table(self::TABLE_NAME)->count());
    }

    public function testRemoveOnlyAffectsTheSubject(): void
    {
        $this->store->store('foo', new CipherKey('foo-key', 'aes256', 'foo-iv'));
        $this->store->store('bar', new CipherKey('bar-key', 'aes256', 'bar-iv'));

        $this->store->remove('foo');
        $this->store->clear();

        self::assertSame('bar-key', $this->store->get('bar')->key);

        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('foo');
    }

    public function testRemoveAndStoreAgain(): void
    {
        $this->store->store('foo', new CipherKey('first-key', 'aes256', 'first-iv'));
        $this->store->remove('foo');
        $this->store->store('foo', new CipherKey('second-key', 'aes256', 'second-iv'));
        $this->store->clear();

        self::assertSame('second-key', $this->store->get('foo')->key);
    }

    public function testMaxSubjectIdLength(): void
    {
        $id = str_repeat('a', 255);

        $this->store->store($id, new CipherKey('the-key', 'aes256', 'the-iv'));
        $this->store->clear();

        self::assertSame('the-key', $this->store->get($id)->key);
    }

    public function testUsesTheTableFromTheMigration(): void
    {
        // the shipped migration must create the table the configured store points at
        $this->store->store('foo', new CipherKey('the-key', 'aes256', 'the-iv'));

        self::assertSame(1, $this->connection->table(self::TABLE_NAME)->count());
    }

    public function testCustomTableName(): void
    {
        // the schema comes from the migration, so its table is renamed instead of created
        $this->connection->getSchemaBuilder()->rename(self::TABLE_NAME, 'custom_crypto_keys');

        $store = new IlluminateCipherKeyStore($this->connection, 'custom_crypto_keys');

        $store->store('foo', new CipherKey('the-key', 'aes256', 'the-iv'));
        $store->clear();

        self::assertSame('the-key', $store->get('foo')->key);
        self::assertSame(1, $this->connection->table('custom_crypto_keys')->count());
    }
}
