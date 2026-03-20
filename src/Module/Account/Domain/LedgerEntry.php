<?php

declare(strict_types=1);

namespace App\Module\Account\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * LedgerEntry — double-entry bookkeeping record for every balance change.
 *
 * Why double-entry bookkeeping?
 * Every financial transaction produces exactly TWO entries:
 *   - DEBIT  on the sender's account   (money leaves)
 *   - CREDIT on the receiver's account (money arrives)
 *
 * This mirrors real-world accounting (banks, Stripe, Wise all do this).
 * The sum of all credits minus all debits for an account must equal its balance.
 * This invariant is your audit trail — if the numbers ever disagree, something went wrong.
 *
 * balanceAfter: a balance snapshot taken immediately after this entry was created.
 * This is critical for auditing — you can reconstruct the full balance history
 * by replaying ledger entries in chronological order without touching the accounts table.
 *
 * transferId is stored as a plain int (no ORM relation) to keep bounded contexts
 * decoupled. The Account module must not import Transfer domain classes.
 * If you need the transfer details, look them up by ID in the Transfer module.
 *
 * Immutability: a ledger entry is never modified after creation — append-only.
 * This is the financial audit trail. If a transfer is reversed, a NEW entry
 * is created (credit on sender, debit on receiver) — the original is untouched.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_entries')]
#[ORM\Index(columns: ['account_id'], name: 'IDX_ledger_account_id')]
#[ORM\Index(columns: ['transfer_id'], name: 'IDX_ledger_transfer_id')]
final class LedgerEntry
{
    public const TYPE_DEBIT  = 'debit';
    public const TYPE_CREDIT = 'credit';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The account whose balance this entry affects.
     * ORM relation is allowed here — both entities are in the Account bounded context.
     */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    /**
     * ID of the transfer that triggered this entry.
     * Stored as plain int — no FK to the transfers table across bounded contexts.
     * The Transfer module is a separate BC; we never import its entities here.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $transferId;

    /** debit = money leaving the account | credit = money entering the account */
    #[ORM\Column(length: 10)]
    private string $type;

    /** Amount in minor units (cents) — same unit as Account.balance */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    /**
     * Account balance immediately after this entry was applied.
     * Enables full balance history reconstruction from ledger alone.
     * Also makes fraud detection easier — anomalous balance jumps are visible.
     */
    #[ORM\Column(type: Types::BIGINT)]
    private int $balanceAfter;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Account $account,
        int $transferId,
        string $type,
        int $amount,
        string $currency,
        int $balanceAfter,
    ) {
        $this->account      = $account;
        $this->transferId   = $transferId;
        $this->type         = $type;
        $this->amount       = $amount;
        $this->currency     = strtoupper($currency);
        $this->balanceAfter = $balanceAfter;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getTransferId(): int
    {
        return $this->transferId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalanceAfter(): int
    {
        return $this->balanceAfter;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
