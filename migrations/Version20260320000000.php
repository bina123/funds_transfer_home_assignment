<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ledger_entries table (double-entry bookkeeping) and reversal_of_transfer_id to transfers';
    }

    public function up(Schema $schema): void
    {
        // ledger_entries: double-entry bookkeeping table.
        // Every successful transfer (or reversal) produces exactly two entries:
        //   - DEBIT  on the sender's account   (money leaves)
        //   - CREDIT on the receiver's account (money arrives)
        //
        // balance_after: a balance snapshot at the moment of the entry.
        // Enables full balance history reconstruction without touching accounts table.
        //
        // transfer_id is stored as a plain INT (no FK constraint) to keep
        // the Account module decoupled from the Transfer module at the DB level.
        $this->addSql('
            CREATE TABLE ledger_entries (
                id              INT             NOT NULL AUTO_INCREMENT,
                account_id      INT             NOT NULL,
                transfer_id     INT             NOT NULL,
                type            VARCHAR(10)     NOT NULL COMMENT "debit or credit",
                amount          BIGINT          NOT NULL COMMENT "minor units (cents)",
                currency        VARCHAR(3)      NOT NULL,
                balance_after   BIGINT          NOT NULL COMMENT "account balance after this entry",
                created_at      DATETIME        NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX IDX_ledger_account_id  (account_id),
                INDEX IDX_ledger_transfer_id (transfer_id),
                CONSTRAINT FK_ledger_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // reversal_of_transfer_id: points back to the original transfer that this record reverses.
        // NULL = a normal transfer.
        // Non-NULL = a reversal; combined with the idempotency_key UNIQUE index this
        //            guarantees at most one reversal per original transfer.
        $this->addSql('
            ALTER TABLE transfers
            ADD COLUMN reversal_of_transfer_id INT NULL DEFAULT NULL
                COMMENT "ID of the original transfer this reverses"
                AFTER failure_reason
        ');
        $this->addSql('ALTER TABLE transfers ADD INDEX IDX_transfers_reversal_of (reversal_of_transfer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transfers DROP INDEX IDX_transfers_reversal_of');
        $this->addSql('ALTER TABLE transfers DROP COLUMN reversal_of_transfer_id');
        $this->addSql('DROP TABLE ledger_entries');
    }
}
