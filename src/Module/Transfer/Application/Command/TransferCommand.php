<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * TransferCommand — the input DTO for the fund transfer use case.
 *
 * CQRS naming:
 * A "Command" represents intent to change state (write side).
 * It carries validated input from the HTTP layer into the Application layer.
 *
 * Validation lives here (Symfony Constraints), not in the domain entity.
 * The entity validates its own invariants (currency match, balance) —
 * the command validates that the HTTP caller provided sensible data.
 *
 * Why separate Command from Request?
 * The HTTP controller parses the raw request into this Command.
 * If we add a CLI or queue-based transfer trigger, they create the same
 * Command — reusing all business logic without touching HTTP code.
 */
final class TransferCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'From account ID is required.')]
        #[Assert\Uuid(message: 'From account ID must be a valid UUID.')]
        public readonly ?string $fromAccountId = null,

        #[Assert\NotBlank(message: 'To account ID is required.')]
        #[Assert\Uuid(message: 'To account ID must be a valid UUID.')]
        public readonly ?string $toAccountId = null,

        #[Assert\NotBlank(message: 'Amount is required.')]
        #[Assert\Positive(message: 'Amount must be a positive integer (in minor units, e.g. cents).')]
        public readonly ?int $amount = null,

        #[Assert\NotBlank(message: 'Currency is required.')]
        #[Assert\Length(exactly: 3, exactMessage: 'Currency must be a 3-letter ISO 4217 code (e.g. EUR, USD).')]
        public readonly ?string $currency = null,

        #[Assert\NotBlank(message: 'Idempotency key is required.')]
        #[Assert\Uuid(message: 'Idempotency key must be a valid UUID v4 (e.g. generated client-side before the first attempt).')]
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    /**
     * Cross-field constraint: source and destination must differ.
     * Self-transfers are a data integrity bug — money would leave and return
     * to the same account, but two balance mutations would still fire.
     */
    #[Assert\IsTrue(message: 'Source and destination accounts must be different.')]
    public function isNotSelfTransfer(): bool
    {
        if ($this->fromAccountId === null || $this->toAccountId === null) {
            return true; // Let NotBlank handle null values first
        }

        return $this->fromAccountId !== $this->toAccountId;
    }
}
