<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

final class SuspendAccountCommand
{
    public function __construct(
        public readonly string $accountUuid,
    ) {
    }
}
