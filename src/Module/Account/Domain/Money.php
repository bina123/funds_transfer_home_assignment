<?php

declare(strict_types=1);

namespace App\Module\Account\Domain;

/**
 * Money — Value Object representing an amount in a specific currency.
 *
 * Why a Value Object?
 * - Immutable: money never mutates; operations produce new values.
 * - Self-validating: an invalid Money (zero/negative) cannot be constructed.
 * - Currency-aware: prevents accidental EUR/USD mixing at the type level.
 *
 * Amounts are always in minor units (cents/grosz/etc.) — the standard used
 * by Stripe, Wise, and Paysera to avoid IEEE 754 floating-point errors
 * (e.g. 0.1 + 0.2 ≠ 0.3 in PHP floats).
 *
 * Modular monolith note:
 * Money belongs to the Account module — it is the Account module's concern
 * to define what "money" means. The Transfer module references it because
 * transfers move money between accounts.
 */
final class Money
{
    private readonly string $currency;

    public function __construct(
        private readonly int $amount,
        string $currency,
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Money amount must be a positive integer (minor units), got %d.', $amount)
            );
        }

        $this->currency = strtoupper($currency);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }
}
