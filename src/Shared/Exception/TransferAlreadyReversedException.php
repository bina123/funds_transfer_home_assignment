<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * TransferAlreadyReversedException — raised when attempting to reverse a transfer
 * that has already been reversed.
 *
 * HTTP 409 Conflict: the current state of the resource conflicts with the request.
 * The client must re-read the transfer state before retrying.
 */
final class TransferAlreadyReversedException extends ApiException
{
    public function __construct(string $identifier)
    {
        parent::__construct(
            message: sprintf('Transfer "%s" has already been reversed.', $identifier),
            statusCode: Response::HTTP_CONFLICT,
            errorCode: 'transfer_already_reversed',
        );
    }
}
