<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

final class CloseAccountCommand
{
    public function __construct(
        public readonly string $accountUuid,
    ) {
    }
}
