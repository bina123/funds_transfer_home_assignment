<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Domain exception — raised when a Money operation crosses currency boundaries.
 *
 * Fintech rule: currency conversion is a separate, explicit operation with
 * exchange rates, fees, and regulatory considerations. Silently mixing
 * currencies is a data integrity bug. This exception makes the violation explicit.
 */
final class CurrencyMismatchException extends \DomainException
{
    public function __construct(string $expected, string $actual)
    {
        parent::__construct(
            sprintf('Currency mismatch: expected %s, got %s.', $expected, $actual)
        );
    }
}
