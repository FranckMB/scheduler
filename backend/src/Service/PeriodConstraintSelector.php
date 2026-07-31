<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\TeamPeriodOverride;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\VenuePeriodMode;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * P2-14 — LA source unique de « quelles contraintes partent au solveur pour cette
 * période ». Avant, la réponse vivait en DEUX exemplaires entretenus à la main :
 * `ScheduleConstraintBuilder::buildForPeriodPlan` (le payload) et
 * `ValidateConstraintsController::constraintsForPeriod` (le gate pré-solve) — dont les
 * commentaires assumaient l'aveu (« miroir EXACT du filtre gymnases »). Deux endroits
 * qui répondent à la même question, c'est le motif qui a produit 40 défauts en 4 rounds
 * sur la bascule ADR-0002 ; ici il avait déjà produit deux divergences réelles, alignées
 * par cette classe :
 *
 * - une contrainte DATÉE visant une équipe désactivée restait validée par le gate alors
 *   que le payload la filtrait (le gate ne filtrait que les permanentes) ;
 * - une contrainte CLUB+tag HARD à gymnase dédié dont toutes les équipes taguées sont en
 *   pause était sortie du gate, alors que le payload émet encore ses lignes « interdit
 *   hors tag » pour les autres équipes.
 *
 * La sélection opère sur les ENTITÉS — ce dont le gate a besoin (`validate()`,
 * `detectConflicts()`, ids d'erreurs). Le builder sérialise ensuite `kept` et garde ses
 * post-filtres sur lignes SÉRIALISÉES en défense en profondeur (ils attrapent les
 * expansions par équipe, invisibles au niveau entité).
 */
final class PeriodConstraintSelector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConstraintRepository $constraintRepository,
        private readonly TeamRepository $teamRepository,
        private readonly TeamTagResolver $tagResolver,
    ) {}

    public function selectForPeriodPlan(string $clubId, string $seasonId, string $schedulePlanId, CalendarEntry $entry): PeriodConstraintSelection
    {
        $periodType = $entry->getPeriodType();
        if (!\in_array($periodType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            throw new LogicException('Period constraint selection supports only closure and holiday periods.');
        }

        // P2-5 E1 : les datées d'une SEMAINE enfant vivent sur sa MÈRE (source unique
        // CalendarEntry::datedConstraintSourceId).
        /** @var list<Constraint> $dated */
        $dated = $this->constraintRepository->findBy(['calendarEntryId' => $entry->datedConstraintSourceId(), 'clubId' => $clubId]);

        $overrides = [];
        foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            $overrides[$override->getConstraintId()] = $override->isActive();
        }

        $deactivatedTeamIds = [];
        $sessionOverrides = [];
        foreach ($this->entityManager->getRepository(TeamPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $teamOverride) {
            if (!$teamOverride->isActive()) {
                $deactivatedTeamIds[$teamOverride->getTeamId()] = true;
            }
            if (null !== $teamOverride->getSessionsPerWeek()) {
                $sessionOverrides[$teamOverride->getTeamId()] = $teamOverride->getSessionsPerWeek();
            }
        }

        $disabledVenueIds = [];
        foreach ($this->entityManager->getRepository(VenuePeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $venueOverride) {
            if (VenuePeriodMode::DISABLED === $venueOverride->getMode()) {
                $disabledVenueIds[$venueOverride->getVenueId()] = true;
            }
        }

        $activeTeamIds = [];
        foreach ($this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            if (!isset($deactivatedTeamIds[$team->getId()])) {
                $activeTeamIds[$team->getId()] = true;
            }
        }

        // Fermeture : TOUT hérité par défaut. Reprise : défaut intelligent qui suit la
        // sélection d'équipes — les FACILITY sont droppées (la période possède sa grille).
        // Un override explicite dévie du défaut dans les deux sens.
        $permanent = [];
        foreach ($this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId) as $constraint) {
            $keepByDefault = CalendarEntryPeriodType::CLOSURE === $periodType || ConstraintScope::FACILITY !== $constraint->getScope();
            if (\array_key_exists($constraint->getId(), $overrides) ? $overrides[$constraint->getId()] : $keepByDefault) {
                $permanent[] = $constraint;
            }
        }

        $kept = [];
        $droppedForDisabledVenue = [];
        foreach ([...$permanent, ...$dated] as $constraint) {
            // Équipe désactivée : sa contrainte TEAM ne produit aucune ligne — permanente
            // OU datée (le gate ne filtrait que les permanentes : divergence alignée ici).
            if (ConstraintScope::TEAM === $constraint->getScope() && isset($deactivatedTeamIds[$constraint->getScopeTargetId() ?? ''])) {
                continue;
            }
            if (!$this->clubTagConstraintProducesRows($constraint, $clubId, $seasonId, $activeTeamIds)) {
                continue;
            }
            // Gymnase désactivé nommé (scope FACILITY, ou une clé de config) : la
            // contrainte ne partira pas — le gestionnaire en est AVERTI, pas mis devant
            // un silence (raison exposée, le gate en fait son warning).
            $disabledVenueId = $this->disabledVenueNamedBy($constraint, $disabledVenueIds);
            if (null !== $disabledVenueId) {
                $droppedForDisabledVenue[] = ['constraint' => $constraint, 'venueId' => $disabledVenueId];

                continue;
            }
            $kept[] = $constraint;
        }

        return new PeriodConstraintSelection(
            schedulePlanId: $schedulePlanId,
            kept: $kept,
            droppedForDisabledVenue: $droppedForDisabledVenue,
            dated: $dated,
            disabledVenueIds: $disabledVenueIds,
            deactivatedTeamIds: $deactivatedTeamIds,
            sessionOverrides: $sessionOverrides,
        );
    }

    /**
     * Une CLUB+targetTag produit-elle ENCORE une ligne de payload ? Miroir exact de
     * l'expansion du builder : tag inconnu/vide → aucune ligne (skip complet) ; sinon des
     * lignes par équipe taguée ACTIVE ; et même toutes taguées en pause, une HARD à
     * gymnase dédié émet encore ses lignes « interdit hors tag » pour les autres équipes.
     * (Le gate sortait cette dernière — c'est la divergence n° 2 que P2-14 aligne.).
     *
     * @param array<string, true> $activeTeamIds
     */
    private function clubTagConstraintProducesRows(Constraint $constraint, string $clubId, string $seasonId, array $activeTeamIds): bool
    {
        $targetTag = $constraint->getConfig()['targetTag'] ?? null;
        if (ConstraintScope::CLUB !== $constraint->getScope() || !\is_string($targetTag) || '' === $targetTag) {
            return true; // pas une CLUB+tag : la règle ne s'applique pas
        }

        $tagTeamIds = $this->tagResolver->tagTeamIds($targetTag, $seasonId, $clubId);
        if ([] === $tagTeamIds) {
            return false; // tag inconnu ou sans équipe : le builder saute la contrainte entière
        }
        foreach ($tagTeamIds as $teamId) {
            if (isset($activeTeamIds[$teamId])) {
                return true;
            }
        }

        $config = $constraint->getConfig();

        return ConstraintRuleType::HARD === $constraint->getRuleType()
            && null !== ($config['forcedVenueId'] ?? $config['preferredVenueId'] ?? null);
    }

    /**
     * L'id du gymnase désactivé que cette contrainte NOMME, ou null. Les deux façons de
     * nommer un gymnase sont celles du builder : le scope FACILITY, et les clés de config
     * de `ScheduleConstraintBuilder::VENUE_CONFIG_KEYS` (source unique).
     *
     * @param array<string, true> $disabledVenueIds
     */
    private function disabledVenueNamedBy(Constraint $constraint, array $disabledVenueIds): ?string
    {
        $scopeTargetId = $constraint->getScopeTargetId();
        if (ConstraintScope::FACILITY === $constraint->getScope() && \is_string($scopeTargetId) && isset($disabledVenueIds[$scopeTargetId])) {
            return $scopeTargetId;
        }

        $config = $constraint->getConfig();
        foreach (ScheduleConstraintBuilder::VENUE_CONFIG_KEYS as $venueKey) {
            $venueId = $config[$venueKey] ?? null;
            if (\is_string($venueId) && isset($disabledVenueIds[$venueId])) {
                return $venueId;
            }
        }

        return null;
    }
}
