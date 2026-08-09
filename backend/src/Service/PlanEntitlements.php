<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P1-3 PR A — l'offre EFFECTIVE d'un club, calculée à la LECTURE (pas de cron, pas
 * d'état stocké). Règles (spec bridage-freemium-decouverte §4-5) :
 *   - `planId` null → Découverte (le défaut de tout compte) ;
 *   - offre payante/bêta dont `paidSeasonYear` < année-pivot de la saison courante
 *     → retombe sur Découverte (expiration) ;
 *   - club `isDemo` → droits pleins TOUJOURS (exempt de tout gate).
 *
 * Rend le socle de droits (DTO tableau) consommé par /api/me — l'ENFORCEMENT
 * (débit des crédits, cap d'équipes) est la PR B, PAS ici : ce service ne fait que
 * DIRE ce que l'offre autorise.
 */
final readonly class PlanEntitlements
{
    private const string DECOUVERTE_CODE = 'decouverte';

    public function __construct(
        private SubscriptionPlanRepository $plans,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return array{
     *     planCode: string,
     *     planName: string,
     *     maxTeams: int|null,
     *     teamsUsed: int,
     *     creditsMax: int|null,
     *     creditsUsed: int,
     *     canGenerate: bool,
     *     canPlaceMatches: bool,
     *     canExportPdf: bool,
     *     seasonTransition: bool
     * }
     */
    public function forClub(Club $club, Season $season): array
    {
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        $effective = $this->effectivePlan($club, $season, $decouverte);

        // Découverte effective = le seul régime bridé. Un club démo n'est JAMAIS
        // bridé, quelle que soit son offre effective.
        $restricted = !$club->isDemo() && self::DECOUVERTE_CODE === $effective?->getCode();

        // $restricted implique une offre effective non-nulle (code === decouverte),
        // narrowing que PHPStan suit depuis la condition ci-dessus.
        $creditsMax = $restricted ? $this->unlimitedToNull($effective->getMaxGenerations()) : null;
        $creditsUsed = $club->getOutputCreditsUsed();
        // Solde > 0 : en Découverte bridée on n'a de sorties QUE tant qu'il reste des
        // crédits (creditsMax null = illimité => solde toujours ouvert).
        $hasOutput = !$restricted || null === $creditsMax || $creditsUsed < $creditsMax;

        return [
            'planCode' => $effective?->getCode() ?? self::DECOUVERTE_CODE,
            'planName' => $effective?->getName() ?? 'Découverte',
            'maxTeams' => $this->unlimitedToNull($effective?->getMaxTeams() ?? 0),
            'teamsUsed' => $this->teamsUsed($club, $season),
            'creditsMax' => $creditsMax,
            'creditsUsed' => $creditsUsed,
            'canGenerate' => $hasOutput,
            'canPlaceMatches' => $hasOutput,
            'canExportPdf' => $hasOutput,
            // Bascule de saison : ouverte en payant/bêta courant ou en démo, fermée
            // en Découverte effective (le seul interrupteur fermé du gratuit).
            'seasonTransition' => !$restricted,
        ];
    }

    private function effectivePlan(Club $club, Season $season, ?SubscriptionPlan $decouverte): ?SubscriptionPlan
    {
        $planId = $club->getPlanId();
        if (null === $planId) {
            return $decouverte;
        }

        $plan = $this->plans->find($planId);
        if (!$plan instanceof SubscriptionPlan || self::DECOUVERTE_CODE === $plan->getCode()) {
            return $plan ?? $decouverte;
        }

        // Offre payante/bêta : effective seulement si la saison courante est réglée
        // (même règle d'année-pivot que SeasonTransitionService). Sinon → Découverte.
        $pivot = SeasonResolver::seasonYear($season->getStartDate());
        if (($club->getPaidSeasonYear() ?? \PHP_INT_MIN) < $pivot) {
            return $decouverte;
        }

        return $plan;
    }

    /** 0 en base = illimité → null côté lecture. */
    private function unlimitedToNull(int $value): ?int
    {
        return 0 === $value ? null : $value;
    }

    /** Toutes les équipes du club dans la saison — inactives comprises (elles comptent au cap). */
    private function teamsUsed(Club $club, Season $season): int
    {
        return $this->entityManager->getRepository(Team::class)
            ->count(['clubId' => $club->getId(), 'seasonId' => $season->getId()]);
    }
}
