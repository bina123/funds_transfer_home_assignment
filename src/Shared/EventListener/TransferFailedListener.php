<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Module\Transfer\Domain\Event\TransferFailedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * TransferFailedListener — reacts to a failed transfer attempt.
 *
 * Responsibilities:
 *   1. Notify the sender why their transfer was rejected
 *   2. Flag the failure to the fraud detection system
 *      (repeated failures on the same account are a fraud signal)
 *
 * OCP: Adding new failure handling (e.g. SMS alert, compliance report) requires
 * only a new listener — TransferCommandHandler and TransferFailedEvent never change.
 *
 * Failure independence: each private method catches its own exceptions.
 * A broken notification service never prevents the fraud system from being updated,
 * and vice versa.
 *
 * The HTTP response is NOT affected by this listener — the exception has already
 * been re-thrown by the handler and is being mapped to HTTP by ExceptionListener.
 * This listener is purely about side effects.
 */
#[AsEventListener(event: TransferFailedEvent::class)]
final class TransferFailedListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TransferFailedEvent $event): void
    {
        $this->notifySenderOfFailure($event);
        $this->flagToFraudSystem($event);
    }

    /**
     * Notify the sender that their transfer was rejected and why.
     *
     * Maps the machine-readable failureReason to a human-readable message.
     * In production, replace the logger call with Symfony Mailer:
     *
     *   $email = (new Email())
     *       ->from('noreply@paysera.com')
     *       ->to($senderEmailAddress)
     *       ->subject('Transfer failed: ' . $event->formattedAmount())
     *       ->text($this->buildMessage($event));
     *   $this->mailer->send($email);
     */
    private function notifySenderOfFailure(TransferFailedEvent $event): void
    {
        try {
            $message = match ($event->failureReason) {
                'insufficient_funds' => sprintf(
                    'Your transfer of %s to account #%d was declined — insufficient balance.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
                'currency_mismatch' => sprintf(
                    'Your transfer of %s to account #%d was declined — currency mismatch.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
                'account_suspended' => sprintf(
                    'Your transfer of %s to account #%d was declined — one of the accounts is suspended.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
                'account_not_found' => sprintf(
                    'Your transfer of %s was declined — destination account #%d does not exist.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
                'transfer_conflict' => sprintf(
                    'Your transfer of %s to account #%d was not processed due to a concurrent update. Please retry.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
                'transfer_limit_exceeded' => sprintf(
                    'Your transfer of %s to account #%d was declined — a transfer limit was exceeded.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
                default => sprintf(
                    'Your transfer of %s to account #%d could not be completed.',
                    $event->formattedAmount(),
                    $event->toAccountId,
                ),
            };

            // Simulated failure notification — replace with real Symfony Mailer in production.
            $this->logger->warning('[NOTIFICATION] Transfer failure notification sent to sender.', [
                'from_account_id' => $event->fromAccountId,
                'to_account_id'   => $event->toAccountId,
                'amount'          => $event->formattedAmount(),
                'failure_reason'  => $event->failureReason,
                'occurred_at'     => $event->occurredAt->format(\DateTimeInterface::ATOM),
                'message'         => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send transfer failure notification.', [
                'idempotency_key' => $event->idempotencyKey,
                'exception'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flag the failed attempt to the fraud detection system.
     *
     * In production, call an internal fraud API:
     *
     *   $this->fraudClient->recordFailedAttempt(
     *       accountId:     $event->fromAccountId,
     *       failureReason: $event->failureReason,
     *       amount:        $event->amount,
     *       currency:      $event->currency,
     *   );
     *
     * Why this matters for fintech:
     *   - Multiple 'insufficient_funds' in quick succession: account may be probing limits
     *   - Multiple 'account_not_found' from the same account: enumeration attack
     *   - 'transfer_conflict' spike: potential race condition exploit attempt
     */
    private function flagToFraudSystem(TransferFailedEvent $event): void
    {
        try {
            $riskLevel = match ($event->failureReason) {
                'account_not_found'       => 'medium', // could be enumeration attack
                'transfer_conflict'       => 'medium', // repeated conflicts are suspicious
                'transfer_limit_exceeded' => 'high',   // attempting large transfers is a strong fraud signal
                default                   => 'low',
            };

            // Simulated fraud flag — replace with real fraud API call in production.
            $this->logger->warning('[FRAUD_SCORE] Failed transfer attempt recorded.', [
                'from_account_id' => $event->fromAccountId,
                'amount'          => $event->formattedAmount(),
                'failure_reason'  => $event->failureReason,
                'risk_level'      => $riskLevel,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flag transfer failure to fraud system.', [
                'idempotency_key' => $event->idempotencyKey,
                'exception'       => $e->getMessage(),
            ]);
        }
    }
}
