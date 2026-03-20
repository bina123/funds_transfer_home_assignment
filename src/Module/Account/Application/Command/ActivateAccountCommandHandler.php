<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

use App\Module\Account\Application\Query\AccountResponse;
use App\Module\Account\Domain\Account;
use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\Event\AccountActivatedEvent;
use App\Shared\Exception\AccountNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ActivateAccountCommandHandler
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(ActivateAccountCommand $command): AccountResponse
    {
        $account = $this->accountRepository->findByUuid($command->accountUuid);

        if ($account === null) {
            throw new AccountNotFoundException($command->accountUuid);
        }

        $wasAlreadyActive = $account->getStatus() === Account::STATUS_ACTIVE;

        $account->activate();
        $this->entityManager->flush();

        // Only dispatch if the account was actually suspended before this call.
        // Re-activating an already-active account is a no-op — no event, no notification.
        if (!$wasAlreadyActive) {
            $this->eventDispatcher->dispatch(new AccountActivatedEvent(
                accountId: (int) $account->getId(),
                currency:  $account->getCurrency(),
            ));
        }

        return AccountResponse::fromAccount($account);
    }
}
