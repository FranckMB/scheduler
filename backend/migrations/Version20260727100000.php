<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Feature #10, lot C3 — emails de collecte.
 *
 * - `coach_wish_token.sent_at` : horodatage du DERNIER envoi du lien par email à ce coach
 *   (null = jamais envoyé). Par token (décision fondateur D1) : un email ajouté tardivement
 *   puis envoyé garde sa propre date.
 * - `coach_wish_campaign.last_digest_at` : dernier digest 7h envoyé — le prochain ne part
 *   que si une réponse (token.responded_at) lui est postérieure (D-digest : silence = rien).
 * - `coach_wish_campaign.last_reminder_at` : dernière relance manuelle — bloque une seconde
 *   relance le même jour Europe/Paris (anti-harcèlement, D3).
 * - `coach_wish_campaign.final_recap_sent_at` : récap final envoyé une seule fois le
 *   lendemain de la deadline (part TOUJOURS, même à zéro réponse — D5).
 */
final class Version20260727100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coach wish collection C3: sent_at on tokens + digest/reminder/recap stamps on campaigns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish_token ADD sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE coach_wish_campaign ADD last_digest_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE coach_wish_campaign ADD last_reminder_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE coach_wish_campaign ADD final_recap_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish_token DROP sent_at');
        $this->addSql('ALTER TABLE coach_wish_campaign DROP last_digest_at');
        $this->addSql('ALTER TABLE coach_wish_campaign DROP last_reminder_at');
        $this->addSql('ALTER TABLE coach_wish_campaign DROP final_recap_sent_at');
    }
}
