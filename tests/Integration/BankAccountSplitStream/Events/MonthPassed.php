<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BankAccountSplitStream\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\EventSourcing\Attribute\SplitStream;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BankAccountSplitStream\AccountId;

#[Event('bank_account.month_passed')]
#[SplitStream]
final class MonthPassed
{
    public function __construct(
        public AccountId $accountId,
        public string $name,
        public int $balanceInCents,
    ) {
    }
}
