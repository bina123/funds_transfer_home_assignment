<?php

declare(strict_types=1);

namespace App\Module\Account\Domain;

interface LedgerRepositoryInterface
{
    /**
     * Persist a new ledger entry (append-only — never updates existing).
     */
    public function save(LedgerEntry $entry): void;

    /**
     * Return all ledger entries for an account, ordered most-recent first.
     *
     * @return LedgerEntry[]
     */
    public function findByAccountId(int $accountId, int $limit = 50, int $offset = 0): array;

    /**
     * Total number of ledger entries for an account.
     * Used to build pagination metadata (total field in the response envelope).
     */
    public function countByAccountId(int $accountId): int;
}
