<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Port;

use App\Module\Transfer\Application\Command\TransferCommand;

interface FailedTransferRecorderInterface
{
    public function record(TransferCommand $command, string $reason): void;
}
