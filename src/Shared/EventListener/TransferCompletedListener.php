<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Module\Transfer\Domain\Event\TransferCompletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * TransferCompletedListener — reacts to a completed transfer.
 *
 * This listener is the correct place for ALL post-transfer side effects:
 *   - Email / push notification to the sender
 *   - Fraud scoring update
 *   - Audit trail logging
 *   - Loyalty points calculation
 *
 * OCP (Open/Closed Principle):
 * Adding a new side effect never touches TransferCommandHandler.
 * You add a new listener class and register it — that is all.
 *
 * Why a listener and not direct calls in the handler?
 * If the email service is down, this listener's exception is caught and logged.
 * The transfer is already committed — it is NOT rolled back because an email failed.
 * Downstream concerns fail independently from the core business operation.
 *
 * Production upgrade path:
 * To make notifications asynchronous (run in a background worker):
 *   1. Install Symfony Messenger: composer require symfony/messenger
 *   2. Make TransferCompletedEvent implement MessageInterface (or use routing config)
 *   3. Configure a transport (Redis, RabbitMQ) in messenger.yaml
 *   4. Change dispatch() call to messageBus->dispatch() in the handler
 * Zero changes to this listener class required.
 */
#[AsEventListener(event: TransferCompletedEvent::class)]
final class TransferCompletedListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TransferCompletedEvent $event): void
    {
        try {
            $this->notifySender($event);
            $this->updateFraudScore($event);
        } catch (\Throwable $e) {
            // Never let a notification failure propagate — the transfer already committed.
            // Log the failure so ops can investigate, but do not surface it to the caller.
            $this->logger->error('Post-transfer notification failed.', [
                'transfer_id' => $event->transferId,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the sender that their transfer was processed.
     *
     * Currently uses structured logging to simulate the notification.
     * To send a real email, replace the log call with Symfony Mailer:
     *
     *   $email = (new Email())
     *       ->from('noreply@paysera.com')
     *       ->to($senderEmailAddress)
     *       ->subject('Transfer confirmed: ' . $event->formattedAmount())
     *       ->text(sprintf(
     *           'Your transfer of %s to account %d has been completed.',
     *           $event->formattedAmount(),
     *           $event->toAccountId
     *       ));
     *   $this->mailer->send($email);
     */
    private function notifySender(TransferCompletedEvent $event): void
    {
        // Simulated email notification — replace with real Symfony Mailer in production.
        $this->logger->info('[NOTIFICATION] Transfer confirmation sent to sender.', [
            'transfer_id'     => $event->transferId,
            'from_account_id' => $event->fromAccountId,
            'to_account_id'   => $event->toAccountId,
            'amount'          => $event->formattedAmount(),
            'occurred_at'     => $event->occurredAt->format(\DateTimeInterface::ATOM),
            'message'         => sprintf(
                'Your transfer of %s to account #%d has been completed successfully.',
                $event->formattedAmount(),
                $event->toAccountId,
            ),
        ]);
    }

    /**
     * Notify the fraud detection system about the completed transfer.
     *
     * In production this would call an internal fraud scoring API:
     *
     *   $this->fraudClient->recordTransaction(
     *       accountId: $event->fromAccountId,
     *       amount:    $event->amount,
     *       currency:  $event->currency,
     *   );
     *
     * High-value transfers or unusual patterns would trigger a risk review.
     */
    private function updateFraudScore(TransferCompletedEvent $event): void
    {
        // Simulated fraud scoring update — replace with real fraud API call in production.
        $this->logger->info('[FRAUD_SCORE] Transfer recorded for fraud analysis.', [
            'transfer_id'     => $event->transferId,
            'from_account_id' => $event->fromAccountId,
            'amount'          => $event->formattedAmount(),
            'risk_flag'       => $event->amount >= 100_000_00 ? 'high_value' : 'normal',
        ]);
    }
}
