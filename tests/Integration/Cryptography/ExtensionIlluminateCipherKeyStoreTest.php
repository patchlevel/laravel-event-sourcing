<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Cryptography;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Patchlevel\Hydrator\Extension\Cryptography\Cipher\CipherKey;
use Patchlevel\Hydrator\Extension\Cryptography\Cipher\OpensslCipher;
use Patchlevel\Hydrator\Extension\Cryptography\Cipher\OpensslCipherKeyFactory;
use Patchlevel\Hydrator\Extension\Cryptography\Store\CipherKeyNotExists;
use Patchlevel\LaravelEventSourcing\Cryptography\ExtensionIlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

use function str_repeat;

/**
 * Mirrors the ExtensionDoctrineCipherKeyStoreTest of patchlevel/event-sourcing, so both stores fulfill the same contract.
 * The schema tests are left out, the schema is managed by the laravel migration here.
 */
#[CoversNothing]
final class ExtensionIlluminateCipherKeyStoreTest extends IntegrationTestCase
{
    private ExtensionIlluminateCipherKeyStore $store;

    public function setUp(): void
    {
        parent::setUp();

        $this->createCryptographyKeysTable();

        $this->store = new ExtensionIlluminateCipherKeyStore($this->connection);
    }

    public function testStoreAndGet(): void
    {
        $key = new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00'));

        $this->store->store($key);

        $loaded = $this->store->get('key-1');

        self::assertSame('key-1', $loaded->id);
        self::assertSame('foo', $loaded->subjectId);
        self::assertSame($key->key, $loaded->key);
        self::assertSame($key->method, $loaded->method);
        self::assertSame('2020-01-01 00:00:00', $loaded->createdAt->format('Y-m-d H:i:s'));
    }

    public function testCreatedAtWithOtherTimezone(): void
    {
        $createdAt = new DateTimeImmutable('2020-01-01 10:00:00', new DateTimeZone('America/New_York'));

        $this->store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', $createdAt));

        $loadedCreatedAt = $this->store->get('key-1')->createdAt;

        // like the doctrine dbal store: postgres keeps the point in time, the others only the wall clock time
        if ($this->connection->getDriverName() === 'pgsql') {
            self::assertSame($createdAt->getTimestamp(), $loadedCreatedAt->getTimestamp());

            return;
        }

        self::assertSame($createdAt->format('Y-m-d H:i:s'), $loadedCreatedAt->format('Y-m-d H:i:s'));
    }

    public function testGetUnknownKey(): void
    {
        $this->expectException(CipherKeyNotExists::class);
        $this->expectExceptionMessage('Cipher key with id "key-1" does not exist.');

        $this->store->get('key-1');
    }

    public function testCurrentKeyFor(): void
    {
        $this->store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        self::assertSame('key-1', $this->store->currentKeyFor('foo')->id);
    }

    public function testCurrentKeyForUnknownSubject(): void
    {
        $this->store->store(new CipherKey('key-1', 'bar', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        $this->expectException(CipherKeyNotExists::class);
        $this->expectExceptionMessage('Cipher key for subject id "foo" does not exist.');

        $this->store->currentKeyFor('foo');
    }

    public function testCurrentKeyForReturnsTheNewestKey(): void
    {
        $this->store->store(new CipherKey('key-b', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-02 00:00:00')));
        $this->store->store(new CipherKey('key-c', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-03 00:00:00')));
        $this->store->store(new CipherKey('key-a', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        self::assertSame('key-c', $this->store->currentKeyFor('foo')->id);
    }

    public function testCurrentKeyForWithSameCreatedAtIsDeterministic(): void
    {
        $this->store->store(new CipherKey('key-b', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));
        $this->store->store(new CipherKey('key-c', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));
        $this->store->store(new CipherKey('key-a', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        // the created_at has only second precision, so the id decides between keys of the same second
        self::assertSame('key-c', $this->store->currentKeyFor('foo')->id);
    }

    public function testOldKeysStayLoadable(): void
    {
        $this->store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));
        $this->store->store(new CipherKey('key-2', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-02 00:00:00')));

        self::assertSame('foo', $this->store->get('key-1')->subjectId);
        self::assertSame('foo', $this->store->get('key-2')->subjectId);
    }

    public function testGetFromAnotherInstance(): void
    {
        $key = new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00'));

        $this->store->store($key);

        $loaded = (new ExtensionIlluminateCipherKeyStore($this->connection))->get('key-1');

        self::assertSame($key->key, $loaded->key);
    }

    public function testStoredKeyDecryptsData(): void
    {
        $cipher = new OpensslCipher();
        $key = (new OpensslCipherKeyFactory())('foo');

        $encrypted = $cipher->encrypt($key, 'john@example.com');

        $this->store->store($key);

        self::assertSame('john@example.com', $cipher->decrypt($this->store->currentKeyFor('foo'), $encrypted));
    }

    public function testStoreDuplicateId(): void
    {
        $this->store->store(new CipherKey('key-1', 'foo', 'first-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        $exception = null;

        try {
            $this->store->store(new CipherKey('key-1', 'bar', 'second-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-02 00:00:00')));
        } catch (UniqueConstraintViolationException $e) {
            $exception = $e;
        }

        self::assertNotNull($exception);
        self::assertSame('first-key', $this->store->get('key-1')->key);
    }

    public function testRemove(): void
    {
        $this->store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));
        $this->store->store(new CipherKey('key-2', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-02 00:00:00')));

        $this->store->remove('key-2');

        self::assertSame('key-1', $this->store->currentKeyFor('foo')->id);

        $this->expectException(CipherKeyNotExists::class);

        $this->store->get('key-2');
    }

    public function testRemoveUnknownKey(): void
    {
        $this->store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        $this->store->remove('key-2');

        self::assertSame('key-1', $this->store->get('key-1')->id);
    }

    public function testRemoveWithSubjectId(): void
    {
        $this->store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));
        $this->store->store(new CipherKey('key-2', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-02 00:00:00')));
        $this->store->store(new CipherKey('key-3', 'bar', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        $this->store->removeWithSubjectId('foo');

        self::assertSame('key-3', $this->store->currentKeyFor('bar')->id);

        $this->expectException(CipherKeyNotExists::class);

        $this->store->currentKeyFor('foo');
    }

    public function testMaxIdLength(): void
    {
        $id = str_repeat('a', 255);
        $subjectId = str_repeat('b', 255);

        $this->store->store(new CipherKey($id, $subjectId, 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        self::assertSame($id, $this->store->currentKeyFor($subjectId)->id);
    }

    public function testCustomTableName(): void
    {
        // the schema comes from the migration, so its table is renamed instead of created
        $this->connection->getSchemaBuilder()->rename('cryptography_keys', 'custom_cryptography_keys');

        $store = new ExtensionIlluminateCipherKeyStore($this->connection, 'custom_cryptography_keys');

        $store->store(new CipherKey('key-1', 'foo', 'the-key', 'aes-256-gcm', new DateTimeImmutable('2020-01-01 00:00:00')));

        self::assertSame('key-1', $store->currentKeyFor('foo')->id);
        self::assertSame(1, $this->connection->table('custom_cryptography_keys')->count());
    }
}
