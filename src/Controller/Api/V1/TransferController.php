<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Module\Transfer\Application\Command\ReversalCommand;
use App\Module\Transfer\Application\Command\ReversalCommandHandler;
use App\Module\Transfer\Application\Command\TransferCommand;
use App\Module\Transfer\Application\Command\TransferCommandHandler;
use App\Module\Transfer\Application\Query\TransferResponse;
use App\Module\Transfer\Domain\TransferRepositoryInterface;
use App\Shared\Exception\TransferNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * TransferController — thin HTTP layer for fund transfer.
 *
 * Responsibility (SRP): parse request → validate → delegate → respond.
 * Zero business logic here. All rules live in TransferCommandHandler and
 * the domain entities (Account.debit, Account.credit).
 *
 * Route versioning (/api/v1): allows introducing breaking changes in v2
 * without disrupting existing clients — standard fintech API practice.
 */
#[Route('/api/v1')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferCommandHandler $handler,
        private readonly ReversalCommandHandler $reversalHandler,
        private readonly ValidatorInterface $validator,
        private readonly TransferRepositoryInterface $transferRepository,
    ) {
    }

    #[Route('/transfers/{uuid}', name: 'api_v1_transfer_show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $transfer = $this->transferRepository->findByUuid($uuid);

        if ($transfer === null) {
            throw new TransferNotFoundException($uuid);
        }

        $reversal = $this->transferRepository->findByReversalOf((int) $transfer->getId());

        return $this->json(TransferResponse::fromTransfer($transfer, $reversal?->getUuid()));
    }

    /**
     * Reverse a completed transfer — refund the full amount back to the original sender.
     *
     * A reversal is a NEW transfer in the opposite direction (receiver → original sender).
     * The original transfer is marked STATUS_REVERSED; its record is never modified.
     * Two new ledger entries are created to maintain the double-entry audit trail.
     *
     * Returns 201 with the reversal Transfer on success.
     * Returns 404 if the original transfer does not exist.
     * Returns 409 if the transfer has already been reversed.
     */
    #[Route('/transfers/{uuid}/reverse', name: 'api_v1_transfer_reverse', methods: ['POST'])]
    public function reverse(string $uuid): JsonResponse
    {
        $response = $this->reversalHandler->handle(new ReversalCommand($uuid));

        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/transfers', name: 'api_v1_transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'error' => 'invalid_json',
                'message' => 'Request body must be valid JSON.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $command = new TransferCommand(
            fromAccountId: isset($data['from_account_id']) ? (string) $data['from_account_id'] : null,
            toAccountId: isset($data['to_account_id']) ? (string) $data['to_account_id'] : null,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        $violations = $this->validator->validate($command);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }

            return $this->json([
                'error' => 'validation_failed',
                'message' => 'Request validation failed.',
                'details' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $response = $this->handler->handle($command);

        return $this->json($response, Response::HTTP_CREATED);
    }
}
