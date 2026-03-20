<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Module\Account\Domain\Event\AccountActivatedEvent;
use App\Module\Account\Domain\Event\AccountClosedEvent;
use App\Module\Account\Domain\Event\AccountCreatedEvent;
use App\Module\Account\Domain\Event\AccountSuspendedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * AccountLifecycleListener — reacts to account state change events.
 *
 * Handles all three account lifecycle events in one class because they share
 * the same infrastructure concern (notifications + compliance alerting) and
 * keeping them together makes the full account lifecycle easy to reason about.
 *
 * OCP: adding a new reaction (e.g. CRM sync, loyalty system update) requires
 * only a NEW listener class — this one never needs to change.
 *
 * Failure independence: each handler method catches its own exceptions.
 * A broken email service never prevents the compliance alert from firing.
 *
 * In production, replace logger calls with:
 *   - Symfony Mailer for email notifications
 *   - An internal compliance/fraud API client
 *   - A message bus for async processing (Symfony Messenger)
 */
#[AsEventListener(event: AccountCreatedEvent::class,   method: 'onAccountCreated')]
#[AsEventListener(event: AccountSuspendedEvent::class, method: 'onAccountSuspended')]
#[AsEventListener(event: AccountActivatedEvent::class, method: 'onAccountActivated')]
#[AsEventListener(event: AccountClosedEvent::class,    method: 'onAccountClosed')]
final class AccountLifecycleListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * React to a new account being opened.
     *
     * Production reactions:
     *   1. Send welcome email with account details and next steps
     *   2. Trigger KYC/AML onboarding pipeline (identity verification)
     *   3. Initialise fraud monitoring baseline for this account ID
     */
    public function onAccountCreated(AccountCreatedEvent $event): void
    {
        $this->notifyAccountCreated($event);
        $this->triggerKycPipeline($event);
    }

    /**
     * React to an account being suspended.
     *
     * Production reactions:
     *   1. Notify the account holder — tell them what happened and what to do next
     *   2. Alert the compliance team — a human needs to review the suspension
     *   3. Escalate fraud monitoring — suspended accounts need closer watching
     */
    public function onAccountSuspended(AccountSuspendedEvent $event): void
    {
        $this->notifyAccountSuspended($event);
        $this->alertComplianceTeam($event);
    }

    /**
     * React to a suspended account being re-activated.
     *
     * Production reactions:
     *   1. Notify the account holder — their account is restored
     *   2. Inform compliance — the hold was lifted, close the review case
     */
    public function onAccountActivated(AccountActivatedEvent $event): void
    {
        $this->notifyAccountActivated($event);
        $this->closeComplianceCase($event);
    }

    // -------------------------------------------------------------------------
    // Private helpers — each catches its own exceptions so one failure
    // never silences another notification.
    // -------------------------------------------------------------------------

    private function notifyAccountCreated(AccountCreatedEvent $event): void
    {
        try {
            // Production: send welcome email via Symfony Mailer.
            $this->logger->info('[NOTIFICATION] Welcome email sent for new account.', [
                'account_id'      => $event->accountId,
                'currency'        => $event->currency,
                'initial_balance' => $event->initialBalance,
                'occurred_at'     => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send account creation notification.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function triggerKycPipeline(AccountCreatedEvent $event): void
    {
        try {
            // Production: POST to internal KYC service to start identity verification.
            // Until KYC passes, the account may have reduced transfer limits.
            $this->logger->info('[KYC] Onboarding pipeline triggered for new account.', [
                'account_id' => $event->accountId,
                'currency'   => $event->currency,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to trigger KYC pipeline.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function notifyAccountSuspended(AccountSuspendedEvent $event): void
    {
        try {
            // Production: send urgent email / push notification.
            // Message must explain what happened and how to appeal the decision.
            $this->logger->warning('[NOTIFICATION] Account suspension notification sent.', [
                'account_id'  => $event->accountId,
                'balance'     => $event->formattedBalance(),
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send account suspension notification.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function alertComplianceTeam(AccountSuspendedEvent $event): void
    {
        try {
            // Production: create a compliance case in an internal case management system.
            // Include the frozen balance so the reviewer can assess risk immediately.
            $this->logger->warning('[COMPLIANCE] Account suspension alert raised for review.', [
                'account_id'     => $event->accountId,
                'frozen_balance' => $event->formattedBalance(),
                'occurred_at'    => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to alert compliance team.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function notifyAccountActivated(AccountActivatedEvent $event): void
    {
        try {
            // Production: send email confirming the hold has been lifted.
            $this->logger->info('[NOTIFICATION] Account activation notification sent.', [
                'account_id'  => $event->accountId,
                'currency'    => $event->currency,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send account activation notification.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * React to an account being permanently closed.
     *
     * Production reactions:
     *   1. Send account closure confirmation email to the account holder
     *   2. Inform compliance — close all open KYC/AML review cases for this account
     *   3. Signal downstream systems (CRM, loyalty) to archive the account
     */
    public function onAccountClosed(AccountClosedEvent $event): void
    {
        try {
            // Production: send closure confirmation email + trigger data archival.
            $this->logger->info('[NOTIFICATION] Account closure confirmation sent.', [
                'account_id'  => $event->accountId,
                'currency'    => $event->currency,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send account closure notification.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }

        try {
            // Production: mark all open compliance cases for this account as resolved.
            $this->logger->info('[COMPLIANCE] Compliance cases closed — account permanently closed.', [
                'account_id'  => $event->accountId,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to close compliance cases on account closure.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function closeComplianceCase(AccountActivatedEvent $event): void
    {
        try {
            // Production: mark the compliance review case as resolved.
            $this->logger->info('[COMPLIANCE] Compliance case closed — account re-activated.', [
                'account_id'  => $event->accountId,
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to close compliance case.', [
                'account_id' => $event->accountId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }
}
