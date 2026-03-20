<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command;

use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\Exception\AccountSuspendedException;
use App\Module\Account\Domain\Exception\CurrencyMismatchException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use App\Module\Account\Domain\LedgerEntry;
use App\Module\Account\Domain\LedgerRepositoryInterface;
use App\Module\Account\Domain\Money;
use App\Module\Transfer\Domain\Exception\TransferLimitExceededException;
use App\Module\Transfer\Application\Query\TransferResponse;
use App\Module\Transfer\Domain\Event\TransferCompletedEvent;
use App\Module\Transfer\Domain\Event\TransferFailedEvent;
use App\Module\Transfer\Domain\Transfer;
use App\Module\Transfer\Domain\TransferRepositoryInterface;
use App\Shared\Exception\AccountNotFoundException;
use App\Shared\Exception\TransferConflictException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * TransferCommandHandler — Application Use Case for fund transfer.
 *
 * SOLID:
 * - SRP: orchestrates steps, delegates rules to domain and infrastructure.
 * - OCP: new transfer types add new handlers, not modify this one.
 * - DIP: depends on interfaces (AccountRepositoryInterface, TransferRepositoryInterface),
 *         never on Doctrine concretions directly for business queries.
 *
 * Concurrency safety:
 * - Everything runs inside ONE explicit database transaction.
 * - Optimistic locking on Account prevents lost-update bugs.
 * - UniqueConstraintViolationException on idempotency_key is caught and resolved
 *   gracefully — duplicate requests always get the original result, never a 500.
 *
 * Scalability:
 * - This handler can be wrapped with Symfony Messenger for async processing
 *   under high load — zero changes to this class required.
 *
 * Domain Events:
 * - TransferCompletedEvent: dispatched after a NEW transfer is persisted successfully.
 *   NOT dispatched for idempotency early-returns (event already fired originally).
 * - TransferFailedEvent: dispatched after ANY business failure (insufficient funds,
 *   currency mismatch, suspended account, account not found, conflict).
 *   Dispatched OUTSIDE the transaction — after rollback — so it always fires exactly once.
 * - Listeners react independently and fail independently from each other.
 */
final class TransferCommandHandler
{
    /**
     * Maximum amount for a single transfer: €100,000.00 (10,000,000 cents).
     *
     * Rationale: transfers above this threshold typically require enhanced KYC/AML
     * checks in regulated markets (e.g. EU AML Directive threshold is €10,000 for
     * cash, but digital transfers often use higher internal limits). This value is
     * deliberately conservative for a retail API.
     *
     * In production: inject from config so compliance teams can adjust without a deploy.
     */
    private const MAX_SINGLE_TRANSFER = 10_000_000; // €100,000.00

    /**
     * Maximum total outgoing amount per account per calendar day: €500,000.00.
     *
     * Rationale: caps the exposure from a single compromised account within one day.
     * Accounts that legitimately need higher limits would be upgraded to a different
     * account tier with relaxed limits (not implemented here — out of scope).
     */
    private const MAX_DAILY_OUTGOING = 50_000_000; // €500,000.00
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly LedgerRepositoryInterface $ledgerRepository,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(TransferCommand $command): TransferResponse
    {
        // Wrap the ENTIRE operation in one explicit ACID transaction.
        // This ensures the idempotency check, balance changes, and Transfer INSERT
        // are all atomic — no partial state can ever be committed to the database.
        try {
            return $this->entityManager->wrapInTransaction(
                fn (): TransferResponse => $this->executeTransfer($command)
            );
        } catch (UniqueConstraintViolationException) {
            // Race condition: two concurrent requests with the same idempotency key
            // both passed the check before either inserted. The UNIQUE INDEX stopped
            // the second INSERT. Now re-query for the winner's result and return it.
            // The client receives 201 with the original transfer — correct idempotent behaviour.
            $this->logger->info('Idempotency race resolved — returning existing transfer.', [
                'idempotency_key' => $command->idempotencyKey,
            ]);

            $existing = $this->transferRepository->findByIdempotencyKey($command->idempotencyKey);

            return TransferResponse::fromTransfer($existing);
        } catch (InsufficientFundsException | CurrencyMismatchException | AccountSuspendedException | AccountNotFoundException | TransferConflictException | TransferLimitExceededException $e) {
            // Business failure — transaction has already been rolled back by wrapInTransaction.
            // Dispatch the failure event OUTSIDE the transaction so it fires regardless of rollback.
            // Then re-throw so ExceptionListener maps it to the correct HTTP response.
            $this->dispatchFailureEvent($command, $e);
            throw $e;
        }
    }

    /**
     * Dispatches TransferFailedEvent and persists a failed Transfer record.
     *
     * Maps each exception type to the machine-readable reason code used
     * throughout the system (matches ExceptionListener error codes).
     *
     * Persistence is skipped for AccountNotFoundException — we cannot create a
     * valid FK reference to an account that does not exist.
     *
     * Wrapped in try/catch so neither persistence nor event dispatch failure
     * swallows the original exception — the HTTP response must still be correct.
     */
    private function dispatchFailureEvent(TransferCommand $command, \Throwable $e): void
    {
        $reason = match (true) {
            $e instanceof InsufficientFundsException     => 'insufficient_funds',
            $e instanceof CurrencyMismatchException      => 'currency_mismatch',
            $e instanceof AccountSuspendedException      => 'account_suspended',
            $e instanceof AccountNotFoundException       => 'account_not_found',
            $e instanceof TransferConflictException      => 'transfer_conflict',
            $e instanceof TransferLimitExceededException => 'transfer_limit_exceeded',
            default                                      => 'internal_error',
        };

        // Persist a failed Transfer row for audit trail, history, and fraud detection.
        // AccountNotFoundException is excluded — no valid account rows to FK-reference.
        // A fresh idempotency key is generated so the original remains available for retries:
        // the client can reuse the same key after resolving the issue (e.g. topping up balance).
        if (!($e instanceof AccountNotFoundException)) {
            $this->persistFailedTransfer($command, $reason);
        }

        try {
            $this->eventDispatcher->dispatch(new TransferFailedEvent(
                fromAccountId:  $command->fromAccountId,
                toAccountId:    $command->toAccountId,
                amount:         $command->amount,
                currency:       $command->currency,
                failureReason:  $reason,
                idempotencyKey: $command->idempotencyKey,
            ));
        } catch (\Throwable $dispatchException) {
            // Never let event dispatch failure hide the original business exception.
            $this->logger->error('Failed to dispatch TransferFailedEvent.', [
                'original_error' => $e->getMessage(),
                'dispatch_error' => $dispatchException->getMessage(),
            ]);
        }
    }

    /**
     * Persists a Transfer row with STATUS_FAILED using DBAL directly.
     *
     * Why DBAL, not ORM?
     * - wrapInTransaction rolled back the DB transaction; the ORM identity map may
     *   contain Account entities with modified in-memory state (debit/credit applied
     *   but never committed). Flushing them would be incorrect.
     * - DBAL inserts outside ORM state are safe and bypass the stale identity map.
     * - A fresh auto-commit INSERT starts its own implicit transaction.
     *
     * Why a new idempotency key?
     * - The original key must remain available for the client to retry the transfer.
     *   If we stored it on the failed row, the idempotency guard would return the
     *   failed transfer on the next attempt instead of re-executing the transfer.
     */
    private function persistFailedTransfer(TransferCommand $command, string $reason): void
    {
        try {
            $conn = $this->entityManager->getConnection();

            // Resolve integer PKs from public-facing UUIDs — required for FK columns.
            $fromId = $conn->fetchOne('SELECT id FROM accounts WHERE uuid = ?', [$command->fromAccountId]);
            $toId   = $conn->fetchOne('SELECT id FROM accounts WHERE uuid = ?', [$command->toAccountId]);

            if ($fromId === false || $toId === false) {
                // At least one account was not found — cannot create a valid FK reference.
                return;
            }

            $conn->insert('transfers', [
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

    private function executeTransfer(TransferCommand $command): TransferResponse
    {
        // Step 1: Idempotency guard — return existing result for duplicate requests.
        // Safe retries: a client retrying after a network timeout never causes a double debit.
        $existing = $this->transferRepository->findByIdempotencyKey($command->idempotencyKey);
        if ($existing !== null) {
            $this->logger->info('Returning existing transfer for idempotency key.', [
                'idempotency_key' => $command->idempotencyKey,
                'transfer_id' => $existing->getId(),
            ]);

            return TransferResponse::fromTransfer($existing);
        }

        // Step 2: Load domain aggregates.
        $fromAccount = $this->accountRepository->findByUuid($command->fromAccountId);
        if ($fromAccount === null) {
            throw new AccountNotFoundException($command->fromAccountId);
        }

        $toAccount = $this->accountRepository->findByUuid($command->toAccountId);
        if ($toAccount === null) {
            throw new AccountNotFoundException($command->toAccountId);
        }

        // Step 3: Build Money Value Object — self-validates amount > 0.
        $money = new Money($command->amount, $command->currency);

        // Step 4: Enforce transfer limits BEFORE touching account balances.
        // These checks are cheap (one aggregate query) and fail-fast — if limits are
        // exceeded we never enter the debit/credit/flush path, so no rollback is needed.
        //
        // Single transfer limit: protects against a single anomalous large transfer.
        if ($money->getAmount() > self::MAX_SINGLE_TRANSFER) {
            throw new TransferLimitExceededException(
                limitType:       'single_transfer',
                attemptedAmount: $money->getAmount(),
                limitAmount:     self::MAX_SINGLE_TRANSFER,
            );
        }

        // Daily outgoing limit: counts only STATUS_COMPLETED transfers from today.
        // Failed transfers never moved money; reversed transfers returned it — neither counts.
        $startOfDay  = new \DateTimeImmutable('today midnight');
        $dailyTotal  = $this->transferRepository->sumDailyOutgoing((int) $fromAccount->getId(), $startOfDay);
        $projectedTotal = $dailyTotal + $money->getAmount();

        if ($projectedTotal > self::MAX_DAILY_OUTGOING) {
            throw new TransferLimitExceededException(
                limitType:       'daily_outgoing',
                attemptedAmount: $projectedTotal,
                limitAmount:     self::MAX_DAILY_OUTGOING,
            );
        }

        // Step 6: Execute transfer — domain enforces all remaining business invariants.
        // Account.debit throws: AccountSuspendedException, CurrencyMismatchException, InsufficientFundsException.
        // Account.credit throws: AccountSuspendedException, CurrencyMismatchException.
        // All are domain exceptions — ExceptionListener maps them to HTTP responses.
        try {
            $fromAccount->debit($money);
            $toAccount->credit($money);

            $transfer = new Transfer(
                idempotencyKey: $command->idempotencyKey,
                fromAccount: $fromAccount,
                toAccount: $toAccount,
                amount: $money->getAmount(),
                currency: $money->getCurrency(),
            );

            // Optimistic locking: Doctrine verifies at flush time that the version
            // we read still matches the DB. Concurrent modification → OptimisticLockException → 409.
            $this->transferRepository->save($transfer);
            $this->entityManager->lock($fromAccount, LockMode::OPTIMISTIC, $fromAccount->getVersion());
            $this->entityManager->lock($toAccount, LockMode::OPTIMISTIC, $toAccount->getVersion());
            $this->entityManager->flush();

            // Double-entry bookkeeping: create two ledger entries in the same transaction.
            // DEBIT on sender (money leaves), CREDIT on receiver (money arrives).
            // balanceAfter captures the account balance at the moment the entry is written —
            // this creates a full, auditable balance history. A bank statement is basically
            // a list of ledger entries ordered by createdAt.
            //
            // The flush below is the second flush in the same wrapInTransaction block,
            // which is perfectly valid — everything is still inside one DB transaction.
            $this->ledgerRepository->save(new LedgerEntry(
                account:      $fromAccount,
                transferId:   (int) $transfer->getId(),
                type:         LedgerEntry::TYPE_DEBIT,
                amount:       $money->getAmount(),
                currency:     $money->getCurrency(),
                balanceAfter: $fromAccount->getBalance(),
            ));
            $this->ledgerRepository->save(new LedgerEntry(
                account:      $toAccount,
                transferId:   (int) $transfer->getId(),
                type:         LedgerEntry::TYPE_CREDIT,
                amount:       $money->getAmount(),
                currency:     $money->getCurrency(),
                balanceAfter: $toAccount->getBalance(),
            ));
            $this->entityManager->flush();

            $this->logger->info('Transfer completed successfully.', [
                'transfer_id' => $transfer->getId(),
                'from_account' => $command->fromAccountId,
                'to_account' => $command->toAccountId,
                'amount' => $money->getAmount(),
                'currency' => $money->getCurrency(),
            ]);

            // Raise Domain Event — only for genuinely new transfers.
            // Idempotency early-returns (above) do NOT reach this line,
            // so the event fires exactly once per unique transfer.
            // Listeners (TransferCompletedListener) react independently:
            // email notification, fraud scoring, audit logging, etc.
            $this->eventDispatcher->dispatch(new TransferCompletedEvent(
                transferId: (int) $transfer->getId(),
                fromAccountId: (int) $fromAccount->getId(),
                toAccountId: (int) $toAccount->getId(),
                amount: $money->getAmount(),
                currency: $money->getCurrency(),
            ));

            return TransferResponse::fromTransfer($transfer);
        } catch (OptimisticLockException $e) {
            $this->logger->warning('Optimistic lock conflict — concurrent modification detected.', [
                'from_account' => $command->fromAccountId,
                'to_account' => $command->toAccountId,
            ]);

            throw new TransferConflictException($e);
        }
    }
}
