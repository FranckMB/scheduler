<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mime\Email;

/**
 * Construit les deux e-mails adressés à l'AUTEUR d'un signalement : l'accusé de
 * réception « bien reçu » (à la soumission) et le « traité + merci » (quand le
 * support clôt le signalement). Sobres, en français, SANS aucun identifiant
 * interne (ni id de signalement, ni id de club/planning) et SANS aucun contenu
 * saisi par l'utilisateur (jamais le message du signalement) : ce sont des
 * messages rassurants à texte constant, pas des références de ticket. Isolé des
 * contrôleurs pour être testable seul.
 */
final class FeedbackMailBuilder
{
    public function __construct(
        // Origine navigateur du lien vers le journal de nouveautés ; vide → lien
        // omis (patron PeriodReminderMailBuilder : mono-origine en dev/e2e).
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
    ) {}

    public function build(string $to): Email
    {
        $lines = [
            'Bonjour,',
            '',
            'Votre signalement est bien enregistré et sera traité.',
            'Merci de nous aider à améliorer ClubScheduler.',
            '',
            'Inutile de répondre à ce message.',
            '',
            'L\'équipe ClubScheduler',
        ];

        return (new Email)
            ->from($this->mailFrom->address())
            ->to($to)
            ->subject('Votre signalement a bien été reçu')
            ->text(implode("\n", $lines));
    }

    /**
     * « Traité + merci » — envoyé à l'auteur quand le support clôt son signalement.
     * Aucun contenu utilisateur : texte constant + un lien vers le journal de
     * nouveautés (omis si l'origine navigateur n'est pas configurée).
     */
    public function buildTreated(string $to): Email
    {
        $lines = [
            'Bonjour,',
            '',
            'Votre signalement a été traité.',
            'Merci d\'avoir contribué à l\'amélioration de l\'application.',
        ];
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = 'Retrouvez les nouveautés de l\'application :';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/nouveautes';
        }
        $lines[] = '';
        $lines[] = 'Inutile de répondre à ce message.';
        $lines[] = '';
        $lines[] = 'L\'équipe ClubScheduler';

        return (new Email)
            ->from($this->mailFrom->address())
            ->to($to)
            ->subject('Votre signalement a été traité')
            ->text(implode("\n", $lines));
    }
}
