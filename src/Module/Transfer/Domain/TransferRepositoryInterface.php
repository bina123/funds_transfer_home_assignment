<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain;

interface TransferRepositoryInterface
{
    public function findById(int $id): ?Transfer;

    public function findByUuid(string $uuid): ?Transfer;

    public function findByIdempotencyKey(string $key): ?Transfer;

    /**
     * Find an existing reversal transfer for the given original transfer ID.
     * Used by ReversalCommandHandler to prevent double-reversals.
     * Returns null if the transfer has not been reversed yet.
     */
    public function findByReversalOf(int $originalTransferId): ?Transfer;

    /**
     * Find all transfers where the given account was sender or receiver.
     * Ordered by most recent first — standard for transaction history.
     *
     * Optional TransferFilter Value Object enables filtering by:
     *   direction (sent/received), currency, status, date range.
     *
     * @return Transfer[]
     */
    public function findByAccountId(
        int $accountId,
        int $limit = 50,
        int $offset = 0,
        ?TransferFilter $filter = null,
    ): array;

    /**
     * Total number of transfers for an account matching the optional filter.
     * Used to build pagination metadata (total field in the response envelope).
     */
    public function countByAccountId(int $accountId, ?TransferFilter $filter = null): int;

    /**
     * Returns a map of [originalTransferId (int) => reversalTransferUuid (string)] for all given IDs.
     * Used to enrich transfer list responses without N+1 queries.
     * @param int[] $transferIds
     * @return array<int, string>
     */
    public function findReversalMapByOriginalIds(array $transferIds): array;

    /**
     * Sum the total amount of all COMPLETED outgoing transfers from the given account
     * since the given datetime (inclusive). Used to enforce daily outgoing limits.
     *
     * Only STATUS_COMPLETED transfers are counted — failed transfers never moved money,
     * and reversed transfers returned money to the account (not counted against the limit).
     */
    public function sumDailyOutgoing(int $accountId, \DateTimeImmutable $since): int;

    public function save(Transfer $transfer): void;
}
