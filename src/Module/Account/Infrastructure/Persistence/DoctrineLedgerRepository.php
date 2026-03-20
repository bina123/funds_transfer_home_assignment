<?php

declare(strict_types=1);

namespace App\Module\Account\Infrastructure\Persistence;

use App\Module\Account\Domain\LedgerEntry;
use App\Module\Account\Domain\LedgerRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LedgerEntry>
 */
class DoctrineLedgerRepository extends ServiceEntityRepository implements LedgerRepositoryInterface
{
    private readonly EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
        $this->entityManager = $registry->getManager();
    }

    public function save(LedgerEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }

    public function countByAccountId(int $accountId): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.account = :accountId')
            ->setParameter('accountId', $accountId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns all ledger entries for an account, most-recent first.
     *
     * @return LedgerEntry[]
     */
    public function findByAccountId(int $accountId, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.account = :accountId')
            ->setParameter('accountId', $accountId)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
