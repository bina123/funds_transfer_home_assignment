<?php

declare(strict_types=1);

namespace App\Module\Account\Application\Command;

use App\Module\Account\Application\Query\AccountResponse;
use App\Module\Account\Domain\Account;
use App\Module\Account\Domain\AccountRepositoryInterface;
use App\Module\Account\Domain\Event\AccountClosedEvent;
use App\Shared\Exception\AccountNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CloseAccountCommandHandler
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(CloseAccountCommand $command): AccountResponse
    {
        $account = $this->accountRepository->findByUuid($command->accountUuid);

        if ($account === null) {
            throw new AccountNotFoundException($command->accountUuid);
        }

        $wasAlreadyClosed = $account->getStatus() === Account::STATUS_CLOSED;

        $account->close();
        $this->entityManager->flush();

        if (!$wasAlreadyClosed) {
            $this->eventDispatcher->dispatch(new AccountClosedEvent(
                accountId: (int) $account->getId(),
                currency:  $account->getCurrency(),
            ));
        }

        return AccountResponse::fromAccount($account);
    }
}
