<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts and transfers tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE accounts (
            id INT AUTO_INCREMENT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            balance BIGINT NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE transfers (
            id INT AUTO_INCREMENT NOT NULL,
            idempotency_key VARCHAR(64) NOT NULL,
            from_account_id INT NOT NULL,
            to_account_id INT NOT NULL,
            amount BIGINT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status VARCHAR(20) NOT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_idempotency_key (idempotency_key),
            INDEX IDX_transfers_from_account (from_account_id),
            INDEX IDX_transfers_to_account (to_account_id),
            CONSTRAINT FK_transfers_from_account FOREIGN KEY (from_account_id) REFERENCES accounts (id),
            CONSTRAINT FK_transfers_to_account FOREIGN KEY (to_account_id) REFERENCES accounts (id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transfers');
        $this->addSql('DROP TABLE accounts');
    }
}
