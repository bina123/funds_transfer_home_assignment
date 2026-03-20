<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * Thrown when an account ID cannot be resolved in the repository.
 * HTTP 404 — the resource does not exist.
 *
 * Lives in Shared/ because both the Transfer use case and Account query
 * can raise it — it crosses module boundaries.
 */
final class AccountNotFoundException extends ApiException
{
    public function __construct(string $identifier)
    {
        parent::__construct(
            sprintf('Account "%s" not found.', $identifier),
            404,
            'account_not_found',
        );
    }
}
