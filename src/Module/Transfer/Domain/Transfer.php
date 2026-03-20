<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain;

use App\Module\Account\Domain\Account;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Transfer — Aggregate Root of the Transfer bounded context.
 *
 * Design decisions:
 * - Immutable after creation: a completed transfer record is never modified.
 *   This is the fintech "append-only ledger" pattern — the audit trail is sacred.
 * - Idempotency key: a unique client-supplied key ensures that retrying a failed
 *   HTTP request never results in a double transfer. Standard fintech practice
 *   (used by Stripe, Wise, Paypal).
 * - Amount stored in minor units (cents) — mirrors Account.balance for consistency.
 *
 * Modular monolith note:
 * Transfer references Account directly because they live in the same process.
 * In a microservice split, the Transfer service would store only accountId (int)
 * and call the Account service API to validate/debit/credit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transfers')]
#[ORM\UniqueConstraint(name: 'uniq_idempotency_key', columns: ['idempotency_key'])]
class Transfer
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Public-facing UUID v7 — exposed in API responses and URLs. */
    #[ORM\Column(type: 'guid', unique: true)]
    private string $uuid;

    #[ORM\Column(length: 64)]
    private string $idempotencyKey;

    /**
     * Internal integer ID of the original transfer this record reverses.
     * Used for DB-level uniqueness guard (idempotency key "reversal_of_{id}").
     * null = this is a normal transfer, not a reversal.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $reversalOfTransferId = null;

    /**
     * UUID of the original transfer this record reverses.
     * Exposed in the API response as reversalOfTransferId (string UUID).
     * Kept separate from the int FK so the API always speaks UUIDs externally.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $reversalOfTransferUuid = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $fromAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $toAccount;

    /** @var int Amount in minor units (cents) */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $idempotencyKey,
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $currency,
        string $status = self::STATUS_COMPLETED,
        ?string $failureReason = null,
        ?int $reversalOfTransferId = null,
        ?string $reversalOfTransferUuid = null,
    ) {
        $this->uuid                   = Uuid::v7()->toRfc4122();
        $this->idempotencyKey         = $idempotencyKey;
        $this->fromAccount            = $fromAccount;
        $this->toAccount              = $toAccount;
        $this->amount                 = $amount;
        $this->currency               = strtoupper($currency);
        $this->status                 = $status;
        $this->failureReason          = $failureReason;
        $this->reversalOfTransferId   = $reversalOfTransferId;
        $this->reversalOfTransferUuid = $reversalOfTransferUuid;
        $this->createdAt              = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getFromAccount(): Account
    {
        return $this->fromAccount;
    }

    public function getToAccount(): Account
    {
        return $this->toAccount;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReversalOfTransferId(): ?int
    {
        return $this->reversalOfTransferId;
    }

    public function getReversalOfTransferUuid(): ?string
    {
        return $this->reversalOfTransferUuid;
    }
}
