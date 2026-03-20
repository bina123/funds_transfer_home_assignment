<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command;

/**
 * ReversalCommand — Command DTO for reversing a completed transfer.
 *
 * CQRS: Commands mutate state. This command says:
 * "Reverse transfer #X so funds flow back to the original sender."
 *
 * The command carries only the original transfer ID. The handler is
 * responsible for loading the transfer, validating it can be reversed,
 * and executing the reversal. The controller knows nothing about business rules.
 */
final class ReversalCommand
{
    public function __construct(
        public readonly string $originalTransferUuid,
    ) {
    }
}
