<?php

declare(strict_types=1);

namespace App\Module\Account\Domain;

/**
 * Repository interface — defined in the Domain layer (Dependency Inversion Principle).
 *
 * SOLID / DDD principle:
 * - The Domain dictates WHAT it needs (this interface).
 * - The Infrastructure delivers HOW (DoctrineAccountRepository).
 * - Application services depend only on this interface, never on Doctrine.
 *
 * Modular monolith scalability benefit:
 * When the Account module is extracted into its own microservice, only the
 * Infrastructure implementation changes (HTTP client instead of Doctrine).
 * The Domain and Application layers require zero modifications.
 */
interface AccountRepositoryInterface
{
    public function findById(int $id): ?Account;

    public function findByUuid(string $uuid): ?Account;

    /**
     * Persist a new or existing Account entity.
     * The caller must flush the EntityManager after saving.
     */
    public function save(Account $account): void;
}
