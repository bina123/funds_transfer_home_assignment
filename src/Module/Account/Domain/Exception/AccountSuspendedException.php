<?php

declare(strict_types=1);

namespace App\Module\Account\Domain\Exception;

/**
 * Domain exception — raised when a transfer is attempted on a non-active account.
 *
 * Fintech accounts have a lifecycle: active → suspended → closed.
 * A suspended account failed a compliance check (KYC/AML).
 * A frozen account is under investigation.
 * Neither should be able to send or receive money.
 *
 * This is a domain rule — the Account entity enforces it, not the service.
 */
final class AccountSuspendedException extends \DomainException
{
    public function __construct(int $accountId, string $status)
    {
        parent::__construct(
            sprintf('Account %d cannot participate in transfers (status: %s).', $accountId, $status)
        );
    }
}
