<?php

declare(strict_types=1);

namespace App\Module\Transfer\Domain;

/**
 * TransferFilter — Value Object for transfer history query filters.
 *
 * A Value Object is immutable and defined by its properties, not an identity.
 * Encapsulating the filter criteria in a VO rather than passing raw arrays:
 *   - Makes the interface contract explicit and type-safe
 *   - Allows future validation rules to live inside this class
 *   - Keeps the repository interface clean (one param vs five optional params)
 *
 * DDD: Value Objects belong to the Domain layer. The filter describes a
 * business concept ("give me all sent transfers in EUR after date X"),
 * not an infrastructure concern.
 */
final class TransferFilter
{
    /**
     * @param string|null $direction  'sent' = fromAccount only, 'received' = toAccount only, null = both
     * @param string|null $currency   ISO 4217 currency code, e.g. 'EUR'
     * @param string|null $status     Transfer::STATUS_COMPLETED | Transfer::STATUS_FAILED
     * @param \DateTimeImmutable|null $fromDate  include transfers on or after this date
     * @param \DateTimeImmutable|null $toDate    include transfers on or before this date
     */
    public function __construct(
        public readonly ?string $direction = null,
        public readonly ?string $currency = null,
        public readonly ?string $status = null,
        public readonly ?\DateTimeImmutable $fromDate = null,
        public readonly ?\DateTimeImmutable $toDate = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->direction === null
            && $this->currency === null
            && $this->status === null
            && $this->fromDate === null
            && $this->toDate === null;
    }
}
