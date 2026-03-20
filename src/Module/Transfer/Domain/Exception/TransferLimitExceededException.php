<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain\Exception;

/**
 * TransferLimitExceededException — raised when a transfer violates a configured limit.
 *
 * Two limit types are enforced:
 *   - single_transfer : the requested amount exceeds the per-transaction maximum.
 *   - daily_outgoing  : the cumulative outgoing amount for today would exceed the daily cap.
 *
 * Why these limits matter in fintech:
 *   - Regulatory: many jurisdictions require enhanced KYC/AML checks above certain thresholds.
 *   - Fraud prevention: unusually large or rapid transfers are a common fraud signal.
 *   - Operational risk: hard limits cap the maximum exposure from a single compromised account.
 *
 * This is a pure domain exception — no HTTP status codes here.
 * The ExceptionListener maps it to HTTP 422 Unprocessable Entity.
 */
final class TransferLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $limitType,
        public readonly int $attemptedAmount,
        public readonly int $limitAmount,
    ) {
        $formatted       = number_format($attemptedAmount / 100, 2);
        $formattedLimit  = number_format($limitAmount / 100, 2);

        $message = match ($limitType) {
            'single_transfer' => sprintf(
                'Transfer amount %.2f exceeds the single transfer limit of %.2f.',
                $attemptedAmount / 100,
                $limitAmount / 100,
            ),
            'daily_outgoing' => sprintf(
                'This transfer would bring your daily outgoing total to %.2f, exceeding the daily limit of %.2f.',
                $attemptedAmount / 100,
                $limitAmount / 100,
            ),
            default => sprintf(
                'Transfer of %.2f exceeds the %s limit of %.2f.',
                $formatted,
                $limitType,
                $formattedLimit,
            ),
        };

        parent::__construct($message);
    }
}
