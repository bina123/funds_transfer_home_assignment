<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

use App\Module\Account\Application\Query\AccountResponse;
use App\Module\Account\Domain\Account;
use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\Event\AccountSuspendedEvent;
use App\Shared\Exception\AccountNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SuspendAccountCommandHandler
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(SuspendAccountCommand $command): AccountResponse
    {
        $account = $this->accountRepository->findByUuid($command->accountUuid);

        if ($account === null) {
            throw new AccountNotFoundException($command->accountUuid);
        }

        $wasAlreadySuspended = $account->getStatus() === Account::STATUS_SUSPENDED;

        $account->suspend();
        $this->entityManager->flush();

        // Only dispatch if the status actually changed — idempotent calls
        // that hit an already-suspended account should not trigger a second
        // notification (the account holder would get a duplicate email).
        if (!$wasAlreadySuspended) {
            $this->eventDispatcher->dispatch(new AccountSuspendedEvent(
                accountId: (int) $account->getId(),
                currency:  $account->getCurrency(),
                balance:   $account->getBalance(),
            ));
        }

        return AccountResponse::fromAccount($account);
    }
}
