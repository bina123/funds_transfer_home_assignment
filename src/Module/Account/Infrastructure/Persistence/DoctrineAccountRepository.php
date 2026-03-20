<?php

declare(strict_types=1);

namespace App\Module\Account\Infrastructure\Persistence;

use App\Module\Account\Domain\Account;
use App\Module\Account\Domain\AccountRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * DoctrineAccountRepository — Infrastructure implementation of AccountRepositoryInterface.
 *
 * Naming convention: "Doctrine" prefix signals this is the ORM-specific implementation.
 * If we later add a CachedAccountRepository (Redis) or HttpAccountRepository
 * (microservice client), each implements the same interface — zero changes to Domain.
 *
 * Extends ServiceEntityRepository for Doctrine convenience (find, findBy, etc.),
 * but only exposes what the interface contracts require.
 *
 * @extends ServiceEntityRepository<Account>
 */
class DoctrineAccountRepository extends ServiceEntityRepository implements AccountRepositoryInterface
{
    private readonly EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
        $this->entityManager = $registry->getManager();
    }

    public function findById(int $id): ?Account
    {
        return $this->find($id);
    }

    public function findByUuid(string $uuid): ?Account
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function save(Account $account): void
    {
        $this->entityManager->persist($account);
    }
}
