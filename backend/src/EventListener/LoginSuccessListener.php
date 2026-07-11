<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * RGPD (rétention) : trace la dernière ACTIVITÉ authentifiée — l'inactivité
 * (purge à 24 mois, préavis à 23) se mesure sur COALESCE(lastLoginAt,
 * createdAt). L'authenticator JWT déclenche LoginSuccessEvent à CHAQUE requête
 * authentifiée (pas seulement au login interactif) : c'est la bonne sémantique
 * d'activité, mais un flush par requête serait ruineux → l'écriture est
 * THROTTLÉE à une par jour (sauf préavis en cours, toujours annulé aussitôt).
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class LoginSuccessListener
{
    private const REFRESH_AFTER = '-1 day';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $stale = null === $user->getLastLoginAt() || $user->getLastLoginAt() < $now->modify(self::REFRESH_AFTER);
        if (!$stale && null === $user->getInactivityWarnedAt()) {
            return; // trace du jour déjà posée, rien à annuler → zéro écriture.
        }

        $user->setLastLoginAt($now);
        $user->setInactivityWarnedAt(null);
        $this->entityManager->flush();
    }
}
