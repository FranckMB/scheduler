<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-16 / P2-4 — l'horloge qui sait MENTIR pour un club de démonstration.
 *
 * Décore le service `clock` : tout consommateur de {@see ClockInterface}
 * (SeasonResolver, OverlayManager, SeasonTransitionService, guards…) reçoit,
 * pour un club dont `demo_today` est posé, cette date-là — et l'heure RÉELLE
 * de la journée (seule la DATE est simulée : les durées, TTL et horodatages
 * intra-journée gardent un sens).
 *
 * Périmètre STRICTEMENT tenant : la date simulée est lue sur le club de la
 * REQUÊTE (`_club_id`, posé par TenantFilterListener APRÈS le firewall — un
 * header spoofé est déjà refusé en amont). Hors requête (workers, crons,
 * commandes) ou hors club (routes publiques, firewall admin — le superadmin ne
 * porte jamais de `_club_id`), l'horloge est VRAIE. Un vrai club a
 * `demo_today` NULL : passage direct, zéro changement de comportement.
 *
 * ⚠ Mémoïsé par (requête → date) et non par service : le worker et les tests
 * réutilisent le même service sur plusieurs contextes — un memo global
 * servirait la date d'un club au suivant.
 */
final class DemoAwareClock implements ClockInterface
{
    /** @var array<string, DateTimeImmutable|null> clubId → demo_today (memo par club) */
    private array $demoTodayByClub = [];

    public function __construct(
        private readonly ClockInterface $inner,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function now(): DateTimeImmutable
    {
        $real = DateTimeImmutable::createFromInterface($this->inner->now());
        $demoToday = $this->currentDemoToday();
        if (!$demoToday instanceof DateTimeImmutable) {
            return $real;
        }

        // La DATE simulée, l'HEURE réelle — dans le fuseau de l'horloge réelle.
        return $real->setDate(
            (int) $demoToday->format('Y'),
            (int) $demoToday->format('n'),
            (int) $demoToday->format('j'),
        );
    }

    public function sleep(float|int $seconds): void
    {
        $this->inner->sleep($seconds);
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return new self($this->inner->withTimeZone($timezone), $this->requestStack, $this->entityManager);
    }

    private function currentDemoToday(): ?DateTimeImmutable
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return null;
        }

        if (!\array_key_exists($clubId, $this->demoTodayByClub)) {
            // find() passe par l'identity map : dans une requête déjà tenant-résolue le
            // club est généralement chargé — coût marginal nul, et RLS s'applique.
            $this->demoTodayByClub[$clubId] = $this->entityManager->find(Club::class, $clubId)?->getDemoToday();
        }

        return $this->demoTodayByClub[$clubId];
    }
}
