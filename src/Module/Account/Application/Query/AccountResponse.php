<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Query;

use App\Module\Account\Domain\Account;

/**
 * AccountResponse — read-only output DTO for the Account query.
 *
 * The `id` field exposes the account's UUID (not the internal integer PK).
 * Clients always identify accounts by UUID — the integer PK is internal only.
 */
final class AccountResponse
{
    private function __construct(
        public readonly string $id,
        public readonly string $currency,
        public readonly string $status,
        public readonly int $balance,
        public readonly string $balanceFormatted,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromAccount(Account $account): self
    {
        return new self(
            id: $account->getUuid(),
            currency: $account->getCurrency(),
            status: $account->getStatus(),
            balance: $account->getBalance(),
            balanceFormatted: number_format($account->getBalance() / 100, 2, '.', ''),
            createdAt: $account->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $account->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
