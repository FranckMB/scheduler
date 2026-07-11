<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Builds the RGPD inactivity warning email (retention: 23-month notice before
 * the 24-month anonymization). Isolated from the cron walker so the rendering
 * is unit-testable on its own (pattern TransitionReminderMailBuilder).
 */
final class InactivityMailBuilder
{
    private const FROM_ADDRESS = 'no-reply@clubscheduler.app';

    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
    ) {}

    public function build(string $to, string $firstName): Email
    {
        $lines = [
            \sprintf('Bonjour %s,', $firstName),
            '',
            'Votre compte ClubScheduler est inactif depuis presque deux ans.',
            'Conformément à notre politique de conservation des données (RGPD), il sera',
            'anonymisé définitivement dans un mois si vous ne vous reconnectez pas d\'ici là.',
            '',
            'Un simple login suffit à conserver votre compte.',
        ];
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/login';
        }

        return (new Email)
            ->from(self::FROM_ADDRESS)
            ->to($to)
            ->subject('⏳ Compte inactif — anonymisation dans un mois (RGPD)')
            ->text(implode("\n", $lines));
    }
}
