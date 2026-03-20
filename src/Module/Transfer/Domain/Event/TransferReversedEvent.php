<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

/**
 * TransferReversedEvent — Domain Event raised when a transfer is reversed.
 *
 * A reversal is a NEW transfer in the opposite direction (toAccount → fromAccount).
 * The original transfer is NOT modified — the ledger is append-only.
 *
 * Subscribers might:
 *   - Notify both parties that a refund was issued
 *   - Flag to compliance / fraud system (reversals can be abuse signals)
 *   - Update downstream analytics / reporting systems
 *
 * originalTransferId: lets subscribers cross-reference the original event
 * and correlate the full transfer lifecycle for audit purposes.
 */
final class TransferReversedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $reversalTransferId,
        public readonly int $originalTransferId,
        public readonly int $fromAccountId,
        public readonly int $toAccountId,
        public readonly int $amount,
        public readonly string $currency,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount / 100, 2) . ' ' . $this->currency;
    }
}
