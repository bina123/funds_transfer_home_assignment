<?php

declare(strict_types=1);

namespace App\Module\Transfer\Infrastructure\Persistence;

use App\Module\Transfer\Domain\Transfer;
use App\Module\Transfer\Domain\TransferFilter;
use App\Module\Transfer\Domain\TransferRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transfer>
 */
class DoctrineTransferRepository extends ServiceEntityRepository implements TransferRepositoryInterface
{
    private readonly EntityManagerInterface $entityManager;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
        $this->entityManager = $registry->getManager();
    }

    public function findById(int $id): ?Transfer
    {
        return $this->find($id);
    }

    public function findByUuid(string $uuid): ?Transfer
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findByIdempotencyKey(string $key): ?Transfer
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    public function findByReversalOf(int $originalTransferId): ?Transfer
    {
        return $this->findOneBy(['reversalOfTransferId' => $originalTransferId]);
    }

    /**
     * Returns the total number of transfers matching the given account and filter.
     * Reuses the same WHERE conditions as findByAccountId for consistency.
     */
    public function countByAccountId(int $accountId, ?TransferFilter $filter = null): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)');

        $direction = $filter?->direction;
        if ($direction === 'sent') {
            $qb->where('t.fromAccount = :accountId');
        } elseif ($direction === 'received') {
            $qb->where('t.toAccount = :accountId');
        } else {
            $qb->where('t.fromAccount = :accountId OR t.toAccount = :accountId');
        }
        $qb->setParameter('accountId', $accountId);

        if ($filter?->currency !== null) {
            $qb->andWhere('t.currency = :currency')
               ->setParameter('currency', strtoupper($filter->currency));
        }
        if ($filter?->status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $filter->status);
        }
        if ($filter?->fromDate !== null) {
            $qb->andWhere('t.createdAt >= :fromDate')
               ->setParameter('fromDate', $filter->fromDate);
        }
        if ($filter?->toDate !== null) {
            $qb->andWhere('t.createdAt <= :toDate')
               ->setParameter('toDate', $filter->toDate);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns transfers where the account is either sender or receiver,
     * with optional filters applied, ordered by most recent first.
     *
     * Direction filter:
     *   'sent'     → only transfers where this account is the sender
     *   'received' → only transfers where this account is the receiver
     *   null       → both (default, matches original behaviour)
     *
     * Dynamic query builder — conditions are added only when a filter value
     * is provided, so the SQL is as efficient as possible.
     *
     * @return Transfer[]
     */
    public function findByAccountId(
        int $accountId,
        int $limit = 50,
        int $offset = 0,
        ?TransferFilter $filter = null,
    ): array {
        $qb = $this->createQueryBuilder('t');

        // Direction determines which account columns to restrict on
        $direction = $filter?->direction;
        if ($direction === 'sent') {
            $qb->where('t.fromAccount = :accountId');
        } elseif ($direction === 'received') {
            $qb->where('t.toAccount = :accountId');
        } else {
            $qb->where('t.fromAccount = :accountId OR t.toAccount = :accountId');
        }
        $qb->setParameter('accountId', $accountId);

        if ($filter?->currency !== null) {
            $qb->andWhere('t.currency = :currency')
               ->setParameter('currency', strtoupper($filter->currency));
        }

        if ($filter?->status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $filter->status);
        }

        if ($filter?->fromDate !== null) {
            $qb->andWhere('t.createdAt >= :fromDate')
               ->setParameter('fromDate', $filter->fromDate);
        }

        if ($filter?->toDate !== null) {
            $qb->andWhere('t.createdAt <= :toDate')
               ->setParameter('toDate', $filter->toDate);
        }

        return $qb->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns a map of [originalTransferId => reversalTransferId] for the given IDs.
     * One query regardless of how many transfers are in the list — no N+1.
     *
     * @param int[] $transferIds
     * @return array<int, int>
     */
    public function findReversalMapByOriginalIds(array $transferIds): array
    {
        if (empty($transferIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('t.uuid, t.reversalOfTransferId')
            ->where('t.reversalOfTransferId IN (:ids)')
            ->setParameter('ids', $transferIds)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['reversalOfTransferId']] = (string) $row['uuid'];
        }

        return $map;
    }

    /**
     * Returns the total amount (in minor units) of completed outgoing transfers
     * from the given account since the given datetime.
     *
     * Uses DQL SUM aggregate — one query, no PHP-side iteration.
     * Null coalesce to 0 handles the case where no transfers exist yet today.
     */
    public function sumDailyOutgoing(int $accountId, \DateTimeImmutable $since): int
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->where('t.fromAccount = :accountId')
            ->andWhere('t.status = :status')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('accountId', $accountId)
            ->setParameter('status', Transfer::STATUS_COMPLETED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function save(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
    }
}
