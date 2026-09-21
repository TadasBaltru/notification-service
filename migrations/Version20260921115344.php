<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921115344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications, notification_deliveries and notification_delivery_attempts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_deliveries (channel VARCHAR(16) NOT NULL, recipient VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, attempts_count INT NOT NULL, sent_via_provider VARCHAR(64) DEFAULT NULL, provider_message_id VARCHAR(255) DEFAULT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, id CHAR(36) NOT NULL, notification_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_delivery_notification_channel (notification_id, channel), INDEX IDX_9475204AEF1A9D84 (notification_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_delivery_attempts (provider VARCHAR(64) NOT NULL, outcome VARCHAR(32) NOT NULL, error_code VARCHAR(64) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, provider_message_id VARCHAR(255) DEFAULT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, id CHAR(36) NOT NULL, delivery_id CHAR(36) NOT NULL, INDEX IDX_1D208CE912136921 (delivery_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notifications (user_id VARCHAR(64) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, requires_user_action TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, id CHAR(36) NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, UNIQUE INDEX UNIQ_6000B0D37FD1C147 (idempotency_key), INDEX idx_notifications_user_id (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification_deliveries ADD CONSTRAINT FK_9475204AEF1A9D84 FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_delivery_attempts ADD CONSTRAINT FK_1D208CE912136921 FOREIGN KEY (delivery_id) REFERENCES notification_deliveries (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_deliveries DROP FOREIGN KEY FK_9475204AEF1A9D84');
        $this->addSql('ALTER TABLE notification_delivery_attempts DROP FOREIGN KEY FK_1D208CE912136921');
        $this->addSql('DROP TABLE notification_deliveries');
        $this->addSql('DROP TABLE notification_delivery_attempts');
        $this->addSql('DROP TABLE notifications');
    }
}
