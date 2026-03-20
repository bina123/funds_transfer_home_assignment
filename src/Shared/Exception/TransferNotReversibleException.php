<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * TransferNotReversibleException — raised when attempting to reverse a transfer
 * that is not in a reversible state.
 *
 * Only STATUS_COMPLETED transfers can be reversed.
 * Failed transfers never moved money — there is nothing to return.
 *
 * HTTP 422 Unprocessable Entity: the request is well-formed but the current
 * state of the resource makes the operation impossible.
 */
final class TransferNotReversibleException extends ApiException
{
    public function __construct(string $identifier, string $currentStatus)
    {
        parent::__construct(
            message: sprintf(
                'Transfer "%s" cannot be reversed — only completed transfers can be reversed (current status: %s).',
                $identifier,
                $currentStatus,
            ),
            statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            errorCode: 'transfer_not_reversible',
        );
    }
}
