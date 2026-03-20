<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * Thrown when a transfer ID cannot be resolved in the repository.
 * HTTP 404 — the resource does not exist.
 */
final class TransferNotFoundException extends ApiException
{
    public function __construct(string $identifier)
    {
        parent::__construct(
            sprintf('Transfer "%s" not found.', $identifier),
            404,
            'transfer_not_found',
        );
    }
}
