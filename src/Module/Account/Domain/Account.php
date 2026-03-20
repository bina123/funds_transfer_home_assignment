<?php

declare(strict_types=1);

namespace App\Module\Account\Domain;

use App\Module\Account\Domain\Exception\AccountSuspendedException;
use App\Module\Account\Domain\Exception\CurrencyMismatchException;
use App\Module\Account\Domain\Exception\InsufficientFundsException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Account — Aggregate Root of the Account bounded context.
 *
 * DDD principles applied:
 * - Aggregate Root: owns and protects its invariants (currency, balance).
 * - Rich Domain Model: business rules (debit, credit) live here, not in services.
 * - Optimistic Locking (@ORM\Version): safe for high-concurrency transfers.
 *   In a high-load fintech system, optimistic locking avoids pessimistic DB locks
 *   that kill throughput, while still preventing lost-update bugs.
 *
 * Modular Monolith note:
 * This entity lives entirely within the Account module. Other modules (Transfer)
 * interact with Account only through AccountRepositoryInterface — never by
 * importing this class directly into their own domain layer.
 *
 * Schema design:
 * - Balance in minor units (cents) — avoids IEEE 754 float errors.
 * - BIGINT — supports balances up to ~92 quadrillion cents.
 * - status: account lifecycle (active → suspended → closed).
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\HasLifecycleCallbacks]
class Account
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Public-facing identifier — UUID v7 (time-ordered).
     * Exposed in all API responses and URLs instead of the integer PK.
     * The integer PK is kept for DB joins and FK relations (performance).
     * UUID v7 is time-ordered, so the clustered index stays sequential —
     * no random-write fragmentation like UUID v4.
     */
    #[ORM\Column(type: 'guid', unique: true)]
    private string $uuid;

    #[ORM\Column(length: 3)]
    private string $currency;

    /**
     * Account lifecycle status.
     * Only 'active' accounts may send or receive money.
     * 'suspended' = compliance hold (KYC/AML failure).
     * 'closed' = account terminated — permanent, no transfers.
     */
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /** @var int Balance stored in minor units (cents) */
    #[ORM\Column(type: Types::BIGINT)]
    private int $balance = 0;

    /**
     * Optimistic lock version — Doctrine increments this on every flush.
     * If two concurrent requests read version=5 and both try to flush,
     * the second flush throws OptimisticLockException, which we surface as HTTP 409.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $currency, int $balance = 0)
    {
        $this->uuid = Uuid::v7()->toRfc4122();
        $this->currency = strtoupper($currency);
        $this->balance = $balance;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Suspend the account — blocks all transfers in and out.
     * Used by compliance teams on KYC/AML failure.
     * A suspended account can be re-activated after review.
     */
    public function suspend(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            throw new \DomainException('Cannot suspend a closed account.');
        }
        if ($this->status === self::STATUS_SUSPENDED) {
            return; // idempotent — suspending an already-suspended account is a no-op
        }
        $this->status = self::STATUS_SUSPENDED;
    }

    /**
     * Re-activate a suspended account — resumes all transfer capability.
     * Called after a compliance review clears the hold.
     *
     * Idempotent: activating an already-active account is a no-op.
     * A closed account can never be re-activated — closure is permanent.
     */
    public function activate(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            throw new \DomainException('Cannot activate a closed account.');
        }
        $this->status = self::STATUS_ACTIVE;
    }

    /**
     * Close the account permanently.
     * Irreversible — a closed account can never be re-opened.
     * Balance must be zero before closing.
     */
    public function close(): void
    {
        if ($this->balance !== 0) {
            throw new \DomainException('Cannot close an account with a non-zero balance.');
        }
        $this->status = self::STATUS_CLOSED;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Debit (withdraw) a Money amount from this account.
     *
     * Domain invariants enforced here (rich domain model / SRP):
     * 1. Currency must match — no silent currency conversion.
     * 2. Balance must be sufficient — no overdraft.
     *
     * Throwing domain exceptions (not HTTP/API exceptions) keeps the
     * entity independent of transport concerns — a core DDD requirement.
     * The ExceptionListener maps these to HTTP responses.
     */
    public function debit(Money $money): void
    {
        if (!$this->isActive()) {
            throw new AccountSuspendedException((int) $this->id, $this->status);
        }

        if ($this->currency !== $money->getCurrency()) {
            throw new CurrencyMismatchException($money->getCurrency(), $this->currency);
        }

        if ($this->balance < $money->getAmount()) {
            throw new InsufficientFundsException((int) $this->id);
        }

        $this->balance -= $money->getAmount();
    }

    /**
     * Credit (deposit) a Money amount into this account.
     *
     * Currency is validated even on credit — a deposit in the wrong currency
     * is a data integrity error, not a user-facing validation issue.
     * Status is checked — you cannot deposit into a closed account.
     */
    public function credit(Money $money): void
    {
        if (!$this->isActive()) {
            throw new AccountSuspendedException((int) $this->id, $this->status);
        }

        if ($this->currency !== $money->getCurrency()) {
            throw new CurrencyMismatchException($money->getCurrency(), $this->currency);
        }

        $this->balance += $money->getAmount();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
