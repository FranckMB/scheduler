<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Builds the "prepare next season" reminder email (transition P2-PR2).
 * Isolated from the cron walker so the rendering is unit-testable on its own.
 */
final class TransitionReminderMailBuilder
{
    private const FROM_ADDRESS = 'no-reply@clubscheduler.app';

    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
    ) {}

    public function build(string $to, string $clubName, string $currentSeasonName, DateTimeImmutable $pivot, int $days): Email
    {
        $red = $days <= 14; // last milestone before the pivot = the "red" alert.
        $subject = \sprintf('%s Préparez la saison suivante — bascule dans %d j', $red ? '🔴' : '⏳', $days);

        $lines = [
            \sprintf('Club : %s', $clubName),
            \sprintf('Saison en cours : %s', $currentSeasonName),
            \sprintf('Bascule de saison : %s', $pivot->format('d/m/Y')),
            '',
            'La saison suivante n\'est pas encore préparée.',
            'Ouvre l\'app et lance « Préparer la saison suivante » pour copier la structure (gymnases, équipes, coachs, contraintes) dans un brouillon librement modifiable.',
        ];
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/';
        }

        return (new Email)
            ->from(self::FROM_ADDRESS)
            ->to($to)
            ->subject($subject)
            ->text(implode("\n", $lines));
    }
}
