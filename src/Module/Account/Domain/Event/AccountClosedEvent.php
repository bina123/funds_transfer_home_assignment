<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

/**
 * AccountClosedEvent — Domain Event raised when an account is permanently closed.
 *
 * Closure is irreversible: once closed, an account can never be re-activated.
 * Balance must be zero before closing — the balance field is therefore always 0,
 * but included for audit-trail completeness.
 *
 * Subscribers react independently:
 *   - Notify the account holder (account closure confirmation)
 *   - Inform the compliance team (close any open KYC/AML cases)
 *   - Signal downstream systems to archive account data
 *
 * Timing: dispatched AFTER the status change is committed to the DB.
 * Listeners can safely query the account and see status = closed.
 */
final class AccountClosedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $accountId,
        public readonly string $currency,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }
}
