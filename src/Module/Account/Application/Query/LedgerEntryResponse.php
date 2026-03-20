<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Query;

use App\Module\Account\Domain\LedgerEntry;

/**
 * LedgerEntryResponse — read-only output DTO for a ledger entry.
 *
 * Maps the domain entity to a JSON-safe structure.
 * The Controller and API clients see this, not the entity directly.
 *
 * amountFormatted: human-readable (e.g. "30.00 EUR") for display in bank statements.
 * balanceAfterFormatted: shows account balance at the time of the entry.
 *
 * JSON key naming follows the standard fintech convention:
 *   type: 'debit' | 'credit'
 *   direction: mirrors 'debit' = money out, 'credit' = money in
 */
final class LedgerEntryResponse
{
    private function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly int $transferId,
        public readonly string $type,
        public readonly int $amount,
        public readonly string $amountFormatted,
        public readonly string $currency,
        public readonly int $balanceAfter,
        public readonly string $balanceAfterFormatted,
        public readonly string $createdAt,
    ) {
    }

    public static function fromEntry(LedgerEntry $entry): self
    {
        return new self(
            id: $entry->getId(),
            accountId: $entry->getAccount()->getId(),
            transferId: $entry->getTransferId(),
            type: $entry->getType(),
            amount: $entry->getAmount(),
            amountFormatted: number_format($entry->getAmount() / 100, 2, '.', '') . ' ' . $entry->getCurrency(),
            currency: $entry->getCurrency(),
            balanceAfter: $entry->getBalanceAfter(),
            balanceAfterFormatted: number_format($entry->getBalanceAfter() / 100, 2, '.', '') . ' ' . $entry->getCurrency(),
            createdAt: $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
