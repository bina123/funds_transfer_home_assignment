<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * Thrown when optimistic locking detects a concurrent modification.
 *
 * HTTP 409 Conflict — the client should retry the request.
 * In a high-load fintech system this is expected behaviour, not a bug.
 * The client (mobile app, backend service) should implement retry-with-backoff.
 */
final class TransferConflictException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Transfer conflict: the account was modified concurrently. Please retry.',
            409,
            'transfer_conflict',
            $previous,
        );
    }
}
