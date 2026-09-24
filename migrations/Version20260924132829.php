<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924132829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace notifications user_id index with (user_id, created_at) for the user list.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notifications_user_id ON notifications');
        $this->addSql('CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notifications_user_created ON notifications');
        $this->addSql('CREATE INDEX idx_notifications_user_id ON notifications (user_id)');
    }
}
