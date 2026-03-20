<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add UUID v7 columns to accounts and transfers.
 *
 * Dual-ID pattern:
 *   - Internal integer PK kept for fast DB joins and FK relations
 *   - UUID v7 column added as the public-facing identifier (all API responses and URLs)
 *
 * UUID v7 is time-ordered (monotonically increasing within a millisecond) so the
 * secondary unique index stays mostly sequential — no random-write fragmentation.
 *
 * Migration strategy for existing rows:
 *   1. Add column as nullable
 *   2. Populate with UUID() — MySQL's built-in UUID function (UUID v1, fine for backfill)
 *   3. Make column NOT NULL and add UNIQUE index
 */
final class Version20260321000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UUID v7 public identifiers to accounts and transfers tables';
    }

    public function up(Schema $schema): void
    {
        // --- accounts ---
        $this->addSql('ALTER TABLE accounts ADD COLUMN uuid VARCHAR(36) NULL AFTER id');
        $this->addSql('UPDATE accounts SET uuid = UUID() WHERE uuid IS NULL');
        $this->addSql('ALTER TABLE accounts MODIFY COLUMN uuid VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_accounts_uuid ON accounts (uuid)');

        // --- transfers ---
        $this->addSql('ALTER TABLE transfers ADD COLUMN uuid VARCHAR(36) NULL AFTER id');
        $this->addSql('UPDATE transfers SET uuid = UUID() WHERE uuid IS NULL');
        $this->addSql('ALTER TABLE transfers MODIFY COLUMN uuid VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_transfers_uuid ON transfers (uuid)');

        // reversal_of_transfer_uuid: UUID of the original transfer (exposed in API response)
        $this->addSql('ALTER TABLE transfers ADD COLUMN reversal_of_transfer_uuid VARCHAR(36) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_accounts_uuid ON accounts');
        $this->addSql('ALTER TABLE accounts DROP COLUMN uuid');

        $this->addSql('DROP INDEX UNIQ_transfers_uuid ON transfers');
        $this->addSql('ALTER TABLE transfers DROP COLUMN uuid');
        $this->addSql('ALTER TABLE transfers DROP COLUMN reversal_of_transfer_uuid');
    }
}
