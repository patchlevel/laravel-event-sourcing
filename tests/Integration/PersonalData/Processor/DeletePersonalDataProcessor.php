<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData\Processor;

use Patchlevel\EventSourcing\Attribute\Processor;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData\Events\PersonalDataRemoved;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyStore;

#[Processor('delete_personal_data')]
final class DeletePersonalDataProcessor
{
    public function __construct(
        private readonly CipherKeyStore $cipherKeyStore,
    ) {
    }

    #[Subscribe(PersonalDataRemoved::class)]
    public function handleProfileCreated(PersonalDataRemoved $event): void
    {
        $this->cipherKeyStore->remove($event->profileId->toString());
    }
}
