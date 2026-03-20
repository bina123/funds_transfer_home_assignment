<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Event;

/**
 * TransferCompletedEvent — Domain Event raised when a fund transfer succeeds.
 *
 * DDD principle:
 * A Domain Event is an immutable record that something significant happened
 * in the domain. It is named in the past tense ("Completed") because it has
 * already happened — it is a fact, not a request.
 *
 * Why raise an event instead of calling services directly from the handler?
 * - SRP: TransferCommandHandler orchestrates the transfer — it should not also
 *   know about email, fraud scoring, push notifications, etc.
 * - OCP: Adding a new downstream reaction (e.g. SMS notification) requires only
 *   adding a new listener — TransferCommandHandler never changes.
 * - Decoupling: Each listener fails independently. A broken email service does
 *   not roll back a completed transfer.
 *
 * What carries the event:
 * Only primitive data — no domain entities. This ensures the event can be
 * serialised to a queue (Symfony Messenger) without Doctrine proxy issues.
 *
 * Production note:
 * In a high-load system this event should be dispatched AFTER the DB transaction
 * commits, via Symfony Messenger, so listeners run asynchronously in a background
 * worker. The current synchronous dispatch is correct for low-to-medium load.
 */
final class TransferCompletedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $transferId,
        public readonly int $fromAccountId,
        public readonly int $toAccountId,
        public readonly int $amount,
        public readonly string $currency,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    /**
     * Human-readable amount for use in notifications (e.g. "25.00 EUR").
     * Converts minor units (cents) back to decimal for display purposes only.
     */
    public function formattedAmount(): string
    {
        return number_format($this->amount / 100, 2) . ' ' . $this->currency;
    }
}
