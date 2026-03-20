<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Event;

/**
 * AccountCreatedEvent — Domain Event raised when a new account is opened.
 *
 * Subscribers react independently:
 *   - Send a welcome email to the account holder
 *   - Trigger the KYC/AML onboarding pipeline
 *   - Initialise fraud monitoring baseline for this account
 *   - Notify downstream analytics / reporting systems
 *
 * Why raise an event instead of calling these directly from the handler?
 *   The handler's job is to create the account and persist it.
 *   It should not know about emails, KYC systems, or fraud tools (SRP).
 *   Adding a new reaction (loyalty points, CRM sync) requires only a new
 *   listener — the handler never changes (OCP).
 */
final class AccountCreatedEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $accountId,
        public readonly string $currency,
        public readonly int $initialBalance,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }
}
