<?php

declare(strict_types=1);

namespace App\Module\Transfer\Infrastructure\Persistence;

use App\Module\Transfer\Application\Command\TransferCommand;
use App\Module\Transfer\Application\Port\FailedTransferRecorderInterface;
use App\Module\Transfer\Domain\Transfer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineFailedTransferRecorder implements FailedTransferRecorderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(TransferCommand $command, string $reason): void
    {
        try {
            // Resolve integer PKs from public-facing UUIDs — required for FK columns.
            $fromId = $this->connection->fetchOne('SELECT id FROM accounts WHERE uuid = ?', [$command->fromAccountId]);
            $toId = $this->connection->fetchOne('SELECT id FROM accounts WHERE uuid = ?', [$command->toAccountId]);

            if ($fromId === false || $toId === false) {
                // At least one account was not found — cannot create a valid FK reference.
                return;
            }

            $this->connection->insert('transfers', [
                'uuid'                      => Uuid::v7()->toRfc4122(),
                'idempotency_key'           => Uuid::v7()->toRfc4122(),
                'from_account_id'           => $fromId,
                'to_account_id'             => $toId,
                'amount'                    => $command->amount,
                'currency'                  => strtoupper($command->currency),
                'status'                    => Transfer::STATUS_FAILED,
                'failure_reason'            => $reason,
                'created_at'                => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'reversal_of_transfer_id'   => null,
                'reversal_of_transfer_uuid' => null,
            ]);

            $this->logger->info('Failed transfer record persisted.', [
                'from_account' => $command->fromAccountId,
                'to_account'   => $command->toAccountId,
                'reason'       => $reason,
            ]);
        } catch (\Throwable $persistException) {
            // Never let persistence failure hide the original business exception.
            $this->logger->error('Failed to persist failed transfer record.', [
                'failure_reason' => $reason,
                'persist_error'  => $persistException->getMessage(),
            ]);
        }
    }
}
