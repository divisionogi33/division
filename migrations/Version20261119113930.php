<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119113930 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE legend_items (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, name VARCHAR(100) NOT NULL, default_daily_hours NUMERIC(5, 2) NOT NULL, multiplier NUMERIC(4, 3) NOT NULL, color_hex VARCHAR(7) NOT NULL, UNIQUE INDEX UNIQ_32BD787377153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE schedule_uploads (id INT AUTO_INCREMENT NOT NULL, file_path VARCHAR(255) NOT NULL, parsed_ai_data JSON DEFAULT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, uploaded_by_id INT NOT NULL, INDEX IDX_1B7B53A9A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shifts (id INT AUTO_INCREMENT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, calculated_hours NUMERIC(6, 2) NOT NULL, location VARCHAR(150) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, user_id INT NOT NULL, legend_item_id INT NOT NULL, INDEX IDX_1D1D712FA76ED395 (user_id), INDEX IDX_1D1D712FD4A23952 (legend_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tasks (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, due_date DATE DEFAULT NULL, is_completed TINYINT NOT NULL, completed_at DATETIME DEFAULT NULL, assigned_to_id INT NOT NULL, shift_id INT DEFAULT NULL, INDEX IDX_50586597F4BD7827 (assigned_to_id), INDEX IDX_50586597BB70BC0E (shift_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, google_id VARCHAR(255) DEFAULT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, military_rank VARCHAR(100) DEFAULT NULL, position VARCHAR(150) DEFAULT NULL, is_approved TINYINT NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), UNIQUE INDEX UNIQ_1483A5E976F5C865 (google_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE schedule_uploads ADD CONSTRAINT FK_1B7B53A9A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE shifts ADD CONSTRAINT FK_1D1D712FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE shifts ADD CONSTRAINT FK_1D1D712FD4A23952 FOREIGN KEY (legend_item_id) REFERENCES legend_items (id)');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597BB70BC0E FOREIGN KEY (shift_id) REFERENCES shifts (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE schedule_uploads DROP FOREIGN KEY FK_1B7B53A9A2B28FE8');
        $this->addSql('ALTER TABLE shifts DROP FOREIGN KEY FK_1D1D712FA76ED395');
        $this->addSql('ALTER TABLE shifts DROP FOREIGN KEY FK_1D1D712FD4A23952');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597F4BD7827');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597BB70BC0E');
        $this->addSql('DROP TABLE legend_items');
        $this->addSql('DROP TABLE schedule_uploads');
        $this->addSql('DROP TABLE shifts');
        $this->addSql('DROP TABLE tasks');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
