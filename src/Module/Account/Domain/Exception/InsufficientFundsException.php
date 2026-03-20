<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Domain exception — pure business rule violation, no HTTP concern.
 *
 * Why domain-level, not API-level?
 * The Account entity enforces its own invariants (DDD aggregate root rule).
 * It must not depend on the HTTP layer. The ExceptionListener in Shared/
 * maps this to a 422 HTTP response — that mapping lives in infrastructure,
 * not in the domain.
 */
final class InsufficientFundsException extends \DomainException
{
    public function __construct(int $accountId)
    {
        parent::__construct(sprintf('Insufficient funds in account %d.', $accountId));
    }
}
