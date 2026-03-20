<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

/**
 * AccountActivatedEvent — Domain Event raised when a suspended account is re-activated.
 *
 * Subscribers react independently:
 *   - Notify the account holder that their account is restored
 *   - Inform the compliance team that the hold was lifted
 *   - Reset fraud monitoring risk level back to baseline
 *
 * This event is only raised when the account transitions from suspended → active.
 * Activating an already-active account is a no-op and does NOT raise this event,
 * because nothing changed — no listener should react to a non-event.
 */
final class AccountActivatedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $accountId,
        public readonly string $currency,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }
}
