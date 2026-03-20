<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

/**
 * TransferFailedEvent — Domain Event raised when a fund transfer cannot be completed.
 *
 * Raised for every business failure AFTER the DB transaction has been rolled back:
 *   - insufficient_funds    : sender balance too low
 *   - currency_mismatch     : transfer currency differs from account currency
 *   - account_suspended     : sender or receiver is suspended / closed
 *   - account_not_found     : one of the account IDs does not exist
 *   - transfer_conflict     : optimistic lock — concurrent modification, client should retry
 *
 * NOT raised for:
 *   - Idempotency early-returns (the original transfer already succeeded)
 *   - Validation errors (caught in the controller before the handler is called)
 *   - Internal/infrastructure errors (these are unexpected — logged at ERROR level separately)
 *
 * Why raise an event on failure?
 *   - Notify the sender their transfer was rejected and why
 *   - Flag repeated failures to the fraud detection system
 *     (e.g. 10 failed "insufficient_funds" attempts in 1 minute is suspicious)
 *   - Build an audit trail of attempted but rejected transfers
 *
 * Timing:
 *   This event is dispatched OUTSIDE the DB transaction. By the time it fires,
 *   the transaction has already been rolled back — no partial state exists in the DB.
 */
final class TransferFailedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $failureReason,
        public readonly string $idempotencyKey,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount / 100, 2) . ' ' . $this->currency;
    }
}
