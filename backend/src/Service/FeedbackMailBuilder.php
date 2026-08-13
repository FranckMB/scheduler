<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mime\Email;

/**
 * Construit l'accusé de réception « bien reçu » envoyé à l'auteur d'un
 * signalement. Sobre, en français, SANS aucun identifiant interne (ni id de
 * signalement, ni id de club/planning) : c'est un message rassurant, pas une
 * référence de ticket. Isolé du contrôleur pour être testable seul.
 */
final class FeedbackMailBuilder
{
    private const string FROM_ADDRESS = 'no-reply@clubscheduler.app';

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
            ->from(self::FROM_ADDRESS)
            ->to($to)
            ->subject('Votre signalement a bien été reçu')
            ->text(implode("\n", $lines));
    }
}
