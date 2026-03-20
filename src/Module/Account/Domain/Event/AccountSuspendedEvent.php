<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

/**
 * AccountSuspendedEvent — Domain Event raised when an account is suspended.
 *
 * Subscribers react independently:
 *   - Notify the account holder immediately (email / push notification)
 *   - Alert the compliance team so they can review the case
 *   - Instruct the fraud system to escalate monitoring on this account
 *
 * balance: captured at suspension time so downstream systems (compliance
 * dashboard, audit trail) know exactly what was frozen without a DB query.
 *
 * Timing: dispatched AFTER the status change is committed to the DB.
 * Listeners can safely query the account and see status = suspended.
 */
final class AccountSuspendedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $accountId,
        public readonly string $currency,
        public readonly int $balance,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function formattedBalance(): string
    {
        return number_format($this->balance / 100, 2) . ' ' . $this->currency;
    }
}
