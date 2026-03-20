<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Module\Account\Domain\Exception\AccountSuspendedException;
use App\Module\Account\Domain\Exception\CurrencyMismatchException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use App\Module\Transfer\Domain\Exception\TransferLimitExceededException;
use App\Shared\Exception\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ExceptionListener — maps exceptions to JSON HTTP responses.
 *
 * Layer responsibilities:
 * - Domain exceptions (InsufficientFunds, CurrencyMismatch): thrown by domain entities.
 *   They carry no HTTP codes — we assign codes here in the infrastructure layer.
 * - ApiException subclasses: thrown by Application/Shared layers with explicit codes.
 * - Unhandled exceptions: logged as errors and returned as 500.
 *
 * OCP (Open/Closed Principle):
 * Adding a new domain exception type only requires adding one case here,
 * without modifying the domain entity or the use case handler.
 *
 * Security: debug details are only exposed in dev/test environments,
 * never in production — prevents internal stack trace leakage.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $appEnv = 'prod',
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Domain exceptions: no HTTP knowledge in the domain, we assign status here.
        if ($exception instanceof AccountSuspendedException) {
            $this->logger->warning('Domain rule violated: ' . $exception->getMessage());
            $event->setResponse(new JsonResponse([
                'error' => 'account_suspended',
                'message' => $exception->getMessage(),
            ], 422));

            return;
        }

        if ($exception instanceof InsufficientFundsException) {
            $this->logger->warning('Domain rule violated: ' . $exception->getMessage());
            $event->setResponse(new JsonResponse([
                'error' => 'insufficient_funds',
                'message' => $exception->getMessage(),
            ], 422));

            return;
        }

        if ($exception instanceof CurrencyMismatchException) {
            $this->logger->warning('Domain rule violated: ' . $exception->getMessage());
            $event->setResponse(new JsonResponse([
                'error' => 'currency_mismatch',
                'message' => $exception->getMessage(),
            ], 422));

            return;
        }

        if ($exception instanceof TransferLimitExceededException) {
            $this->logger->warning('Transfer limit exceeded.', [
                'limit_type'       => $exception->limitType,
                'attempted_amount' => $exception->attemptedAmount,
                'limit_amount'     => $exception->limitAmount,
            ]);
            $event->setResponse(new JsonResponse([
                'error'          => 'transfer_limit_exceeded',
                'message'        => $exception->getMessage(),
                'limit_type'     => $exception->limitType,
                'limit_amount'   => $exception->limitAmount,
            ], 422));

            return;
        }

        // DomainException: business rule violations thrown directly by domain entities
        // (e.g. suspending a closed account, activating a closed account).
        // Must come AFTER all specific subclass checks (TransferLimitExceededException
        // extends \DomainException) so the specific handler fires first.
        if ($exception instanceof \DomainException) {
            $this->logger->warning('Domain rule violated: ' . $exception->getMessage());
            $event->setResponse(new JsonResponse([
                'error'   => 'domain_rule_violated',
                'message' => $exception->getMessage(),
            ], 422));

            return;
        }

        // ApiException: Application/Shared exceptions that already carry HTTP metadata.
        if ($exception instanceof ApiException) {
            $this->logger->warning('API error: ' . $exception->getMessage(), [
                'error_code' => $exception->getErrorCode(),
                'status_code' => $exception->getStatusCode(),
            ]);

            $response = new JsonResponse([
                'error' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());

            // 409 Conflict (optimistic lock): tell the client to retry after a short backoff.
            // Without this header, clients may retry immediately and create a thundering herd.
            if ($exception->getStatusCode() === 409) {
                $response->headers->set('Retry-After', '1');
            }

            $event->setResponse($response);

            return;
        }

        // Unhandled: internal error — log everything, expose nothing in prod.
        $this->logger->error('Unhandled exception: ' . $exception->getMessage(), [
            'exception' => $exception::class,
            'trace' => $exception->getTraceAsString(),
        ]);

        $payload = [
            'error' => 'internal_error',
            'message' => 'An internal server error occurred.',
        ];

        if ($this->appEnv === 'dev' || $this->appEnv === 'test') {
            $payload['debug'] = [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }

        $event->setResponse(new JsonResponse($payload, 500));
    }
}
