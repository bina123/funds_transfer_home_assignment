<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Module\Transfer\Domain\Event\TransferReversedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * TransferReversedListener — reacts to a completed transfer reversal.
 *
 * This listener is the correct place for ALL post-reversal side effects:
 *   - Email / push notification to the original sender (their money is back)
 *   - Email / push notification to the original receiver (funds have been reclaimed)
 *   - Fraud scoring update — reversals can be abuse signals (e.g. dispute fraud)
 *   - Compliance / audit trail logging
 *
 * Why a separate listener from TransferCompletedListener?
 * A reversal has different business meaning: two parties need notifying (both sender
 * and receiver of the original transfer), and the fraud signal is different
 * (a reversal shortly after a transfer is a suspicious pattern).
 *
 * OCP (Open/Closed Principle):
 * Adding a new reversal side effect never touches ReversalCommandHandler.
 * You add a new listener class and register it — that is all.
 *
 * Why a listener and not direct calls in the handler?
 * If the email service is down, this listener's exception is caught and logged.
 * The reversal is already committed — it is NOT rolled back because a notification failed.
 * Downstream concerns fail independently from the core business operation.
 *
 * Production upgrade path:
 * To make notifications asynchronous (run in a background worker):
 *   1. Install Symfony Messenger: composer require symfony/messenger
 *   2. Make TransferReversedEvent implement MessageInterface (or use routing config)
 *   3. Configure a transport (Redis, RabbitMQ) in messenger.yaml
 *   4. Change dispatch() call to messageBus->dispatch() in the handler
 * Zero changes to this listener class required.
 */
#[AsEventListener(event: TransferReversedEvent::class)]
final class TransferReversedListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TransferReversedEvent $event): void
    {
        try {
            $this->notifyOriginalSender($event);
            $this->notifyOriginalReceiver($event);
            $this->updateFraudScore($event);
        } catch (\Throwable $e) {
            // Never let a notification failure propagate — the reversal already committed.
            // Log the failure so ops can investigate, but do not surface it to the caller.
            $this->logger->error('Post-reversal notification failed.', [
                'reversal_transfer_id'  => $event->reversalTransferId,
                'original_transfer_id'  => $event->originalTransferId,
                'exception'             => $e::class,
                'message'               => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the original sender that their funds have been returned.
     *
     * Currently uses structured logging to simulate the notification.
     * To send a real email, replace the log call with Symfony Mailer:
     *
     *   $email = (new Email())
     *       ->from('noreply@paysera.com')
     *       ->to($senderEmailAddress)
     *       ->subject('Refund received: ' . $event->formattedAmount())
     *       ->text(sprintf(
     *           'Your transfer of %s (transfer #%d) has been reversed. '
     *           . 'The funds have been returned to your account.',
     *           $event->formattedAmount(),
     *           $event->originalTransferId
     *       ));
     *   $this->mailer->send($email);
     */
    private function notifyOriginalSender(TransferReversedEvent $event): void
    {
        // Simulated email notification — replace with real Symfony Mailer in production.
        $this->logger->info('[NOTIFICATION] Refund confirmation sent to original sender.', [
            'reversal_transfer_id'  => $event->reversalTransferId,
            'original_transfer_id'  => $event->originalTransferId,
            'from_account_id'       => $event->fromAccountId,
            'amount'                => $event->formattedAmount(),
            'occurred_at'           => $event->occurredAt->format(\DateTimeInterface::ATOM),
            'message'               => sprintf(
                'Your transfer of %s (transfer #%d) has been reversed. Funds returned to your account.',
                $event->formattedAmount(),
                $event->originalTransferId,
            ),
        ]);
    }

    /**
     * Notify the original receiver that funds have been reclaimed from their account.
     *
     * This notification is unique to reversals — completed transfers only notify the sender.
     * The receiver needs to know their balance decreased so they are not surprised.
     *
     * In production:
     *   $email = (new Email())
     *       ->from('noreply@paysera.com')
     *       ->to($receiverEmailAddress)
     *       ->subject('Transfer reversed: ' . $event->formattedAmount())
     *       ->text(sprintf(
     *           'A transfer of %s credited to your account (transfer #%d) has been reversed.',
     *           $event->formattedAmount(),
     *           $event->originalTransferId
     *       ));
     *   $this->mailer->send($email);
     */
    private function notifyOriginalReceiver(TransferReversedEvent $event): void
    {
        // Simulated email notification — replace with real Symfony Mailer in production.
        $this->logger->info('[NOTIFICATION] Reversal notice sent to original receiver.', [
            'reversal_transfer_id'  => $event->reversalTransferId,
            'original_transfer_id'  => $event->originalTransferId,
            'to_account_id'         => $event->toAccountId,
            'amount'                => $event->formattedAmount(),
            'occurred_at'           => $event->occurredAt->format(\DateTimeInterface::ATOM),
            'message'               => sprintf(
                'The transfer of %s credited to your account (transfer #%d) has been reversed.',
                $event->formattedAmount(),
                $event->originalTransferId,
            ),
        ]);
    }

    /**
     * Notify the fraud detection system about the reversal.
     *
     * Reversals shortly after a transfer are a known fraud pattern (dispute abuse).
     * The fraud system should correlate this event with the original transfer.
     *
     * In production:
     *   $this->fraudClient->recordReversal(
     *       originalTransferId: $event->originalTransferId,
     *       reversalTransferId: $event->reversalTransferId,
     *       amount:             $event->amount,
     *   );
     */
    private function updateFraudScore(TransferReversedEvent $event): void
    {
        // Simulated fraud scoring update — replace with real fraud API call in production.
        $this->logger->info('[FRAUD_SCORE] Reversal recorded for fraud analysis.', [
            'reversal_transfer_id'  => $event->reversalTransferId,
            'original_transfer_id'  => $event->originalTransferId,
            'from_account_id'       => $event->fromAccountId,
            'amount'                => $event->formattedAmount(),
            'risk_flag'             => 'reversal',
        ]);
    }
}
