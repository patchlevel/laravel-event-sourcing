<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData;

use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootRegistry;
use Patchlevel\EventSourcing\Metadata\Event\AttributeEventRegistryFactory;
use Patchlevel\EventSourcing\Repository\DefaultRepositoryManager;
use Patchlevel\EventSourcing\Repository\Repository;
use Patchlevel\EventSourcing\Serializer\DefaultEventSerializer;
use Patchlevel\EventSourcing\Serializer\Encoder\JsonEncoder;
use Patchlevel\EventSourcing\Serializer\EventSerializer;
use Patchlevel\Hydrator\CoreExtension;
use Patchlevel\Hydrator\Cryptography\PayloadCryptographer;
use Patchlevel\Hydrator\Cryptography\PersonalDataPayloadCryptographer;
use Patchlevel\Hydrator\Extension\Cryptography\BaseCryptographer;
use Patchlevel\Hydrator\Extension\Cryptography\CryptographyExtension;
use Patchlevel\Hydrator\StackHydratorBuilder;
use Patchlevel\LaravelEventSourcing\Cryptography\ExtensionIlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Cryptography\IlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Store\StreamIlluminateStore;
use Patchlevel\LaravelEventSourcing\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The events use the legacy attributes, so this also covers the legacy metadata mapping of the extension.
 */
#[CoversNothing]
final class HydratorExtensionPersonalDataTest extends IntegrationTestCase
{
    private ExtensionIlluminateCipherKeyStore $cipherKeyStore;

    public function setUp(): void
    {
        parent::setUp();

        $this->createCryptographyKeysTable();

        $this->cipherKeyStore = new ExtensionIlluminateCipherKeyStore($this->connection);
    }

    public function testPersonalDataIsEncrypted(): void
    {
        $repository = $this->repository($this->extensionSerializer());

        $profileId = ProfileId::generate();
        $repository->save(Profile::create($profileId, 'John'));

        $profile = $repository->load($profileId);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('John', $profile->name());

        $rows = $this->connection->table('event_store')->get();

        self::assertCount(1, $rows);
        self::assertStringNotContainsString('John', $rows[0]->event_payload);
        self::assertSame(1, $this->connection->table('cryptography_keys')->where('subject_id', $profileId->toString())->count());
    }

    public function testRemovedKeyFallsBack(): void
    {
        $repository = $this->repository($this->extensionSerializer());

        $profileId = ProfileId::generate();
        $repository->save(Profile::create($profileId, 'John'));

        $this->cipherKeyStore->removeWithSubjectId($profileId->toString());

        $profile = $this->repository($this->extensionSerializer())->load($profileId);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('unknown', $profile->name());
    }

    public function testLegacyEncryptedDataStaysReadable(): void
    {
        $legacyCryptographer = PersonalDataPayloadCryptographer::createWithOpenssl(
            new IlluminateCipherKeyStore($this->connection, 'crypto_keys'),
        );

        $profileId = ProfileId::generate();
        $this->repository(DefaultEventSerializer::createFromPaths([__DIR__ . '/Events'], cryptographer: $legacyCryptographer))
            ->save(Profile::create($profileId, 'John'));

        $repository = $this->repository($this->extensionSerializer($legacyCryptographer));

        $profile = $repository->load($profileId);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('John', $profile->name());
        self::assertSame(0, $this->connection->table('cryptography_keys')->count());

        // new events are encrypted by the extension
        $profile->changeName('Jane');
        $repository->save($profile);

        $profile = $this->repository($this->extensionSerializer($legacyCryptographer))->load($profileId);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('Jane', $profile->name());
        self::assertSame(1, $this->connection->table('cryptography_keys')->where('subject_id', $profileId->toString())->count());
    }

    private function extensionSerializer(PayloadCryptographer|null $legacyCryptographer = null): EventSerializer
    {
        $hydrator = (new StackHydratorBuilder())
            ->useExtension(new CoreExtension())
            ->useExtension(new CryptographyExtension(
                BaseCryptographer::createWithOpenssl($this->cipherKeyStore),
                $legacyCryptographer,
                true,
            ))
            ->build();

        return new DefaultEventSerializer(
            (new AttributeEventRegistryFactory())->create([__DIR__ . '/Events']),
            $hydrator,
            new JsonEncoder(),
        );
    }

    /** @return Repository<Profile> */
    private function repository(EventSerializer $serializer): Repository
    {
        $manager = new DefaultRepositoryManager(
            new AggregateRootRegistry(['profile' => Profile::class]),
            new StreamIlluminateStore($this->connection, $serializer),
        );

        return $manager->get(Profile::class);
    }
}
