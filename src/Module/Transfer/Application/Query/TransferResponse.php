<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Query;

use App\Module\Transfer\Domain\Transfer;

/**
 * TransferResponse — read-only output DTO for the Transfer use case.
 *
 * All identifier fields (id, fromAccountId, toAccountId, reversalOfTransferId, reversedBy)
 * are UUID strings — the internal integer PKs are never exposed externally.
 */
final class TransferResponse
{
    private function __construct(
        public readonly string $id,
        public readonly string $idempotencyKey,
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $failureReason,
        public readonly ?string $reversalOfTransferId,
        public readonly ?string $reversedBy,
        public readonly string $createdAt,
    ) {
    }

    public static function fromTransfer(Transfer $transfer, ?string $reversedBy = null): self
    {
        return new self(
            id: $transfer->getUuid(),
            idempotencyKey: $transfer->getIdempotencyKey(),
            fromAccountId: $transfer->getFromAccount()->getUuid(),
            toAccountId: $transfer->getToAccount()->getUuid(),
            amount: $transfer->getAmount(),
            currency: $transfer->getCurrency(),
            status: $transfer->getStatus(),
            failureReason: $transfer->getFailureReason(),
            reversalOfTransferId: $transfer->getReversalOfTransferUuid(),
            reversedBy: $reversedBy,
            createdAt: $transfer->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
