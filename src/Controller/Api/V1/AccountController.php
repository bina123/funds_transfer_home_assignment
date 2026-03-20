<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Module\Account\Application\Command\ActivateAccountCommand;
use App\Module\Account\Application\Command\ActivateAccountCommandHandler;
use App\Module\Account\Application\Command\CloseAccountCommand;
use App\Module\Account\Application\Command\CloseAccountCommandHandler;
use App\Module\Account\Application\Command\CreateAccountCommand;
use App\Module\Account\Application\Command\CreateAccountCommandHandler;
use App\Module\Account\Application\Command\SuspendAccountCommand;
use App\Module\Account\Application\Command\SuspendAccountCommandHandler;
use App\Module\Account\Application\Query\AccountResponse;
use App\Module\Account\Application\Query\LedgerEntryResponse;
use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\LedgerRepositoryInterface;
use App\Module\Transfer\Application\Query\TransferResponse;
use App\Module\Transfer\Domain\TransferFilter;
use App\Module\Transfer\Domain\TransferRepositoryInterface;
use App\Shared\Exception\AccountNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * AccountController — thin HTTP layer for account reads.
 *
 * Depends on repository interfaces (DIP), never on Doctrine concretions.
 * For simple reads, injecting the interface directly is acceptable.
 * If queries become complex (joins, filters, aggregates), extract a QueryHandler.
 */
#[Route('/api/v1')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly LedgerRepositoryInterface $ledgerRepository,
        private readonly CreateAccountCommandHandler $createHandler,
        private readonly SuspendAccountCommandHandler $suspendHandler,
        private readonly ActivateAccountCommandHandler $activateHandler,
        private readonly CloseAccountCommandHandler $closeHandler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Create a new account.
     *
     * Fields:
     *   currency        (required) ISO 4217 3-letter code, e.g. "EUR"
     *   initial_balance (optional) Starting balance in minor units (cents). Defaults to 0.
     *
     * Returns 201 Created with the full account resource.
     */
    #[Route('/accounts', name: 'api_v1_account_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'error'   => 'invalid_json',
                'message' => 'Request body must be valid JSON.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $command = new CreateAccountCommand(
            currency:       $data['currency'] ?? null,
            initialBalance: isset($data['initial_balance']) ? (int) $data['initial_balance'] : 0,
        );

        $violations = $this->validator->validate($command);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = [
                    'field'   => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }

            return $this->json([
                'error'   => 'validation_failed',
                'message' => 'Request validation failed.',
                'details' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $response = $this->createHandler->handle($command);

        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/accounts/{uuid}', name: 'api_v1_account_show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $account = $this->accountRepository->findByUuid($uuid);

        if ($account === null) {
            throw new AccountNotFoundException($uuid);
        }

        return $this->json(AccountResponse::fromAccount($account));
    }

    /**
     * Suspend an account — blocks all incoming and outgoing transfers.
     *
     * Used by compliance teams when a KYC/AML check fails or fraud is suspected.
     * A suspended account can be re-activated via POST /accounts/{id}/activate.
     * Idempotent: suspending an already-suspended account returns 200 with no change.
     *
     * Returns 422 if the account is already closed (closed accounts are permanent).
     */
    #[Route('/accounts/{uuid}/suspend', name: 'api_v1_account_suspend', methods: ['POST'])]
    public function suspend(string $uuid): JsonResponse
    {
        return $this->json($this->suspendHandler->handle(new SuspendAccountCommand($uuid)));
    }

    /**
     * Re-activate a suspended account — restores full transfer capability.
     *
     * Called after a compliance review clears the hold.
     * Idempotent: activating an already-active account returns 200 with no change.
     *
     * Returns 422 if the account is closed (closed accounts cannot be re-opened).
     */
    #[Route('/accounts/{uuid}/activate', name: 'api_v1_account_activate', methods: ['POST'])]
    public function activate(string $uuid): JsonResponse
    {
        return $this->json($this->activateHandler->handle(new ActivateAccountCommand($uuid)));
    }

    /**
     * Close an account permanently.
     *
     * Closure is irreversible — a closed account can never be re-activated or re-opened.
     * The account balance must be zero before closing; transfer the remaining balance out first.
     *
     * Returns 422 if the account has a non-zero balance.
     * Returns 422 if the account is already closed (idempotent: safe to call twice).
     *
     * Why permanent closure? Regulatory requirement — in most jurisdictions, once an
     * account is closed the transaction history must be preserved but the account
     * cannot be reused. A new account must be opened instead.
     */
    #[Route('/accounts/{uuid}/close', name: 'api_v1_account_close', methods: ['POST'])]
    public function close(string $uuid): JsonResponse
    {
        return $this->json($this->closeHandler->handle(new CloseAccountCommand($uuid)));
    }

    /**
     * Transfer history for an account — both sent and received transfers.
     *
     * Supports pagination (limit/offset) and optional filters:
     *   ?direction=sent|received  — only outgoing or incoming transfers
     *   ?currency=EUR             — only transfers in this currency
     *   ?status=completed|failed  — filter by transfer outcome
     *   ?from_date=2024-01-01     — transfers on or after this date (UTC)
     *   ?to_date=2024-12-31       — transfers on or before this date (UTC)
     *
     * Invalid date formats are silently ignored (filter not applied).
     */
    #[Route('/accounts/{uuid}/transfers', name: 'api_v1_account_transfers', methods: ['GET'])]
    public function transfers(string $uuid, Request $request): JsonResponse
    {
        $account = $this->accountRepository->findByUuid($uuid);

        if ($account === null) {
            throw new AccountNotFoundException($uuid);
        }

        $limit  = min((int) $request->query->get('limit', 50), 100);
        $offset = max((int) $request->query->get('offset', 0), 0);

        // Build TransferFilter Value Object from query params.
        // null values mean "no filter" — the repository applies only non-null criteria.
        $direction = $request->query->get('direction');
        $currency  = $request->query->get('currency');
        $status    = $request->query->get('status');
        $fromDate  = $this->parseDate($request->query->get('from_date'));
        $toDate    = $this->parseDate($request->query->get('to_date'));

        $filter = new TransferFilter(
            direction: in_array($direction, ['sent', 'received'], true) ? $direction : null,
            currency:  $currency !== null ? strtoupper($currency) : null,
            status:    in_array($status, ['completed', 'failed'], true) ? $status : null,
            fromDate:  $fromDate,
            toDate:    $toDate,
        );

        $transfers = $this->transferRepository->findByAccountId((int) $account->getId(), $limit, $offset, $filter);
        $total     = $this->transferRepository->countByAccountId((int) $account->getId(), $filter);

        $ids         = array_map(static fn ($t) => (int) $t->getId(), $transfers);
        $reversalMap = $this->transferRepository->findReversalMapByOriginalIds($ids);

        return $this->json([
            'data'   => array_map(
                static fn ($t) => TransferResponse::fromTransfer($t, $reversalMap[$t->getId()] ?? null),
                $transfers,
            ),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Ledger (double-entry bookkeeping) for an account.
     *
     * Returns all balance change events for this account in reverse-chronological order.
     * Each entry shows: type (debit/credit), amount, currency, and the account balance
     * immediately after the entry was applied (balanceAfter).
     *
     * Use this endpoint to build a bank statement or audit trail.
     * The balanceAfter values form a complete, verifiable balance history.
     */
    #[Route('/accounts/{uuid}/ledger', name: 'api_v1_account_ledger', methods: ['GET'])]
    public function ledger(string $uuid, Request $request): JsonResponse
    {
        $account = $this->accountRepository->findByUuid($uuid);

        if ($account === null) {
            throw new AccountNotFoundException($uuid);
        }

        $limit  = min((int) $request->query->get('limit', 50), 100);
        $offset = max((int) $request->query->get('offset', 0), 0);

        $entries = $this->ledgerRepository->findByAccountId((int) $account->getId(), $limit, $offset);
        $total   = $this->ledgerRepository->countByAccountId((int) $account->getId());

        return $this->json([
            'data'   => array_map(
                static fn ($entry) => LedgerEntryResponse::fromEntry($entry),
                $entries,
            ),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Parse a date string into a DateTimeImmutable.
     * Returns null if the string is absent or not a valid date — filters are optional.
     */
    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
