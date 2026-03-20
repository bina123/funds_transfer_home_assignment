<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * CreateAccountCommand — input DTO for the account creation use case.
 *
 * CQRS: this Command carries validated caller intent from the HTTP layer
 * into the Application layer. The controller parses the raw JSON,
 * builds this command, validates it, then hands it to the handler.
 *
 * Why initial_balance is optional:
 * New accounts typically start at zero — balance is funded via a
 * deposit transfer from an internal funding account. Allowing an
 * initial balance here is a convenience for fixtures and testing.
 */
final class CreateAccountCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'Currency is required.')]
        #[Assert\Length(exactly: 3, exactMessage: 'Currency must be a 3-letter ISO 4217 code (e.g. EUR, USD).')]
        public readonly ?string $currency = null,

        #[Assert\PositiveOrZero(message: 'Initial balance must be zero or a positive integer (in minor units).')]
        public readonly int $initialBalance = 0,
    ) {
    }
}
