<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

use App\Module\Account\Application\Query\AccountResponse;
use App\Module\Account\Domain\Account;
use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\Event\AccountCreatedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * CreateAccountCommandHandler — Application Use Case for account creation.
 *
 * Deliberately simple compared to TransferCommandHandler:
 * - No idempotency key required (account creation is a one-time admin action)
 * - No optimistic locking needed (no concurrent writes to the same new entity)
 * - No domain events here — extend with AccountCreatedEvent if downstream
 *   systems (notifications, KYC pipeline) need to react to new accounts
 *
 * In a production system, account creation would be part of a longer
 * KYC/AML onboarding flow — not a direct API call. This endpoint is
 * intentionally simplified for the assignment scope.
 *
 * SRP: this handler only creates accounts.
 * DIP: depends on AccountRepositoryInterface, never on Doctrine directly.
 */
final class CreateAccountCommandHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(CreateAccountCommand $command): AccountResponse
    {
        $account = new Account(
            currency: (string) $command->currency,
            balance:  $command->initialBalance,
        );

        $this->accountRepository->save($account);
        $this->entityManager->flush();

        // Dispatch after flush so the account ID is available and the record
        // is committed — listeners that query the DB will see the new account.
        $this->eventDispatcher->dispatch(new AccountCreatedEvent(
            accountId:      (int) $account->getId(),
            currency:       $account->getCurrency(),
            initialBalance: $account->getBalance(),
        ));

        return AccountResponse::fromAccount($account);
    }
}
