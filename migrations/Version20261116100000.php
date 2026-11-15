<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Editable ticket PDF templates (sections per template, template per event) (the default content is loaded by a fixture)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ticket_template (id UUID NOT NULL, name VARCHAR(255) NOT NULL, is_default BOOLEAN NOT NULL, PRIMARY KEY (id))');
        // At most one default template.
        $this->addSql('CREATE UNIQUE INDEX uniq_ticket_template_default ON ticket_template (is_default) WHERE (is_default)');
        $this->addSql('CREATE TABLE ticket_template_section (id UUID NOT NULL, template_id UUID NOT NULL, position INT NOT NULL, content TEXT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ticket_template_section_template ON ticket_template_section (template_id)');
        $this->addSql('ALTER TABLE ticket_template_section ADD CONSTRAINT FK_TICKET_TEMPLATE_SECTION_TEMPLATE FOREIGN KEY (template_id) REFERENCES ticket_template (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event ADD ticket_template_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_TICKET_TEMPLATE FOREIGN KEY (ticket_template_id) REFERENCES ticket_template (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3BAE0AA7694A09C7 ON event (ticket_template_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP CONSTRAINT FK_EVENT_TICKET_TEMPLATE');
        $this->addSql('DROP INDEX IDX_3BAE0AA7694A09C7');
        $this->addSql('ALTER TABLE event DROP ticket_template_id');
        $this->addSql('DROP TABLE ticket_template_section');
        $this->addSql('DROP TABLE ticket_template');
    }
}
