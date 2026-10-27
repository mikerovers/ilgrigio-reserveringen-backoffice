<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refresh token family columns required by gesdinet/jwt-refresh-token-bundle 3';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_tokens ADD family VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_tokens ADD family_valid TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_refresh_token_family ON refresh_tokens (family)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_refresh_token_family');
        $this->addSql('ALTER TABLE refresh_tokens DROP family');
        $this->addSql('ALTER TABLE refresh_tokens DROP family_valid');
    }
}
