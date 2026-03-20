<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status column to accounts table for account lifecycle management';
    }

    public function up(Schema $schema): void
    {
        // Default 'active' — all existing accounts remain operational.
        // NOT NULL with DEFAULT enforces that every account always has a known status.
        $this->addSql("ALTER TABLE accounts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER currency");
        $this->addSql("ALTER TABLE accounts ADD INDEX IDX_accounts_status (status)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP INDEX IDX_accounts_status');
        $this->addSql('ALTER TABLE accounts DROP COLUMN status');
    }
}
