<?php

declare(strict_types=1);

namespace App\Module\Transfer\Application\Command;

use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\LedgerEntry;
use App\Module\Account\Domain\LedgerRepositoryInterface;
use App\Module\Account\Domain\Money;
use App\Module\Transfer\Application\Query\TransferResponse;
use App\Module\Transfer\Domain\Event\TransferReversedEvent;
use App\Module\Transfer\Domain\Transfer;
use App\Module\Transfer\Domain\TransferRepositoryInterface;
use App\Shared\Exception\AccountNotFoundException;
use App\Shared\Exception\TransferAlreadyReversedException;
use App\Shared\Exception\TransferNotFoundException;
use App\Shared\Exception\TransferNotReversibleException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use App\Shared\Exception\TransferConflictException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * ReversalCommandHandler — Application Use Case for reversing a completed transfer.
 *
 * What a reversal does:
 *   - Creates a NEW Transfer in the opposite direction (toAccount → fromAccount)
 *   - Creates two new ledger entries (credit sender, debit receiver)
 *   - Dispatches TransferReversedEvent
 *
 * What a reversal does NOT do:
 *   - Modify the original transfer's status or any of its fields (append-only ledger).
 *     The original stays STATUS_COMPLETED — history is immutable. Reversal status is
 *     derived: if a Transfer row exists with reversalOfTransferId = original.id,
 *     the original has been reversed. The API surfaces this as the "reversedBy" field.
 *   - Create a new idempotency key from the client — we generate "reversal_of_{id}"
 *     so the UNIQUE constraint guarantees only one reversal per transfer, always.
 *
 * Guards:
 *   1. Original must exist (404 if not found)
 *   2. Original must be STATUS_COMPLETED (cannot reverse a failed transfer — nothing moved)
 *   3. No reversal transfer referencing this original already exists (409 if one does)
 *
 * SOLID:
 *   - SRP: this class only handles reversals. TransferCommandHandler handles new transfers.
 *   - OCP: adding a "partial reversal" feature creates a new handler, not modifying this.
 *   - DIP: depends on interfaces, never Doctrine concretions.
 */
final class ReversalCommandHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly LedgerRepositoryInterface $ledgerRepository,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(ReversalCommand $command): TransferResponse
    {
        try {
            return $this->entityManager->wrapInTransaction(
                fn (): TransferResponse => $this->executeReversal($command)
            );
        } catch (OptimisticLockException $e) {
            $this->logger->warning('Optimistic lock conflict during reversal.', [
                'original_transfer_id' => $command->originalTransferId,
            ]);
            throw new TransferConflictException($e);
        }
    }

    private function executeReversal(ReversalCommand $command): TransferResponse
    {
        // Step 1: Load the original transfer.
        $original = $this->transferRepository->findByUuid($command->originalTransferUuid);
        if ($original === null) {
            throw new TransferNotFoundException($command->originalTransferUuid);
        }

        // Step 2: Guard — only COMPLETED transfers can be reversed.
        // Failed transfers never moved money — there is nothing to return.
        if ($original->getStatus() !== Transfer::STATUS_COMPLETED) {
            throw new TransferNotReversibleException($command->originalTransferUuid, $original->getStatus());
        }

        // Step 3: Guard — idempotency / double-reversal prevention.
        // If a reversal already exists (by reversalOfTransferId), reject.
        $existingReversal = $this->transferRepository->findByReversalOf((int) $original->getId());
        if ($existingReversal !== null) {
            throw new TransferAlreadyReversedException($command->originalTransferUuid);
        }

        // Step 4: Load the accounts (we need their live state for debit/credit).
        // The reversal moves money in the OPPOSITE direction: toAccount → fromAccount.
        $refundFrom = $this->accountRepository->findById((int) $original->getToAccount()->getId());
        $refundTo   = $this->accountRepository->findById((int) $original->getFromAccount()->getId());

        if ($refundFrom === null) {
            throw new AccountNotFoundException($original->getToAccount()->getUuid());
        }
        if ($refundTo === null) {
            throw new AccountNotFoundException($original->getFromAccount()->getUuid());
        }

        // Step 5: Execute the reverse money movement via domain rules.
        $money = new Money($original->getAmount(), $original->getCurrency());
        $refundFrom->debit($money);   // receiver of original now sends back
        $refundTo->credit($money);    // original sender gets their money back

        // Step 6: Create the reversal Transfer record.
        // idempotency_key = "reversal_of_{id}" — the UNIQUE INDEX stops any race condition.
        $reversalTransfer = new Transfer(
            idempotencyKey:           'reversal_of_' . $original->getId(),
            fromAccount:              $refundFrom,
            toAccount:                $refundTo,
            amount:                   $original->getAmount(),
            currency:                 $original->getCurrency(),
            reversalOfTransferId:     (int) $original->getId(),
            reversalOfTransferUuid:   $original->getUuid(),
        );
        $this->transferRepository->save($reversalTransfer);

        // Step 7 (removed): original transfer is NEVER mutated — append-only ledger.
        // Reversal status is derived by querying findByReversalOf(originalId).

        // Step 8: Optimistic lock on both accounts.
        $this->entityManager->lock($refundFrom, LockMode::OPTIMISTIC, $refundFrom->getVersion());
        $this->entityManager->lock($refundTo,   LockMode::OPTIMISTIC, $refundTo->getVersion());
        $this->entityManager->flush();

        // Step 9: Double-entry ledger entries for the reversal.
        // Mirror the original entries in reverse: DEBIT on the original receiver, CREDIT on original sender.
        $this->ledgerRepository->save(new LedgerEntry(
            account:      $refundFrom,
            transferId:   (int) $reversalTransfer->getId(),
            type:         LedgerEntry::TYPE_DEBIT,
            amount:       $original->getAmount(),
            currency:     $original->getCurrency(),
            balanceAfter: $refundFrom->getBalance(),
        ));
        $this->ledgerRepository->save(new LedgerEntry(
            account:      $refundTo,
            transferId:   (int) $reversalTransfer->getId(),
            type:         LedgerEntry::TYPE_CREDIT,
            amount:       $original->getAmount(),
            currency:     $original->getCurrency(),
            balanceAfter: $refundTo->getBalance(),
        ));
        $this->entityManager->flush();

        $this->logger->info('Transfer reversed successfully.', [
            'original_transfer_id' => $original->getId(),
            'reversal_transfer_id' => $reversalTransfer->getId(),
        ]);

        $this->eventDispatcher->dispatch(new TransferReversedEvent(
            reversalTransferId: (int) $reversalTransfer->getId(),
            originalTransferId: (int) $original->getId(),
            fromAccountId:      $refundFrom->getId(),
            toAccountId:        $refundTo->getId(),
            amount:             $original->getAmount(),
            currency:           $original->getCurrency(),
        ));

        return TransferResponse::fromTransfer($reversalTransfer);
    }
}
