<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BankAccountSplitStream\Projection;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BankAccountSplitStream\Events\BalanceAdded;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BankAccountSplitStream\Events\BankAccountCreated;

#[Projector('dummy-1')]
final class BankAccountProjector
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Setup]
    public function create(): void
    {
        $this->connection->getSchemaBuilder()->create('projection_bank_account', function (Blueprint $table): void {
            $table->string('id', 36);
            $table->string('name', 255);
            $table->integer('balance_in_cents');
            $table->primary('id');
        });
    }

    #[Teardown]
    public function drop(): void
    {
        $this->connection->getSchemaBuilder()->drop('projection_bank_account');
    }

    #[Subscribe(BankAccountCreated::class)]
    public function handleBankAccountCreated(Message $message): void
    {
        $event = $message->event();

        $this->connection->statement(
            'INSERT INTO projection_bank_account (id, name, balance_in_cents) VALUES(:id, :name, 0);',
            [
                'id' => $event->accountId->toString(),
                'name' => $event->name,
            ],
        );
    }

    #[Subscribe(BalanceAdded::class)]
    public function handleBalanceAdded(Message $message): void
    {
        $event = $message->event();

        $this->connection->statement(
            'UPDATE projection_bank_account SET balance_in_cents = balance_in_cents + :balance WHERE id = :id;',
            [
                'id' => $event->accountId->toString(),
                'balance' => $event->balanceInCents,
            ],
        );
    }
}
