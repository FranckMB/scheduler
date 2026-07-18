<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Enum\ConstraintFamily;
use App\Enum\SchedulePlanType;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Throwable;

/**
 * ADR-0002 Lot A — the SINGLE point that creates SchedulePlan rows and links a
 * Schedule to its plan/version. Reused at every season- and schedule-creation
 * site so the new model stays in sync additively (nothing legacy is touched).
 *
 * A plan is born FROM A CALENDAR EVENT (ADR-0002, lot C — décision fondateur
 * 2026-07-17). There are exactly two birth sites, and no other:
 *
 * - ensureSeasonPlan(): the SEASON plan exists as soon as the season does (an
 *   empty "espace de travail" with no version yet).
 * - provisionPeriodPlan(): le plan CLOSURE/HOLIDAY naît au geste — "ajuster une période
 *   de vacances / un souci du calendrier", c'est-à-dire la création de la CalendarEntry.
 *   Appelé depuis CalendarEntryStateProcessor (seul site de src/ qui crée une entrée) et
 *   ATOMIQUE avec elle. Les périodes se configurent AVANT toute génération : le plan doit
 *   donc précéder les réglages qui s'y accrochent (inv. 5) — le créer à la première
 *   version, comme le lot A, était simplement trop tard. Naissance seule : l'identité
 *   d'une période qui porte un plan est gelée (422), il n'y a donc rien à synchroniser.
 * - linkSchedule(): ADR-0002 C4 — NUMÉROTE une version dans son plan. Le plan est POSÉ par
 *   l'appelant (`schedulePlanId`) : le POST le nomme (ScheduleStateProcessor), le regenerate
 *   reprend celui de la source. linkSchedule ne résout donc plus rien, il pose `versionNumber`
 *   sous verrou. Idempotent : une version déjà numérotée garde son numéro.
 *
 * ⚠️ Une VERSION SANS PLAN n'existe pas (ruling 2026-07-17) : choose() ne self-heal plus (il
 * ne pourrait plus retrouver la période — `calendarEntryId` a disparu du schedule) ; un
 * `schedulePlanId` null y répond false, l'anomalie est purgée, jamais réparée.
 *
 * Existence/version lookups run as RAW SQL on purpose: the request-scoped
 * ORM season_filter pins every ORM read to the active season, but the
 * provisioner operates on the SCHEDULE's OWN season (overlays bind to their
 * period's season, which may differ). Raw SQL dodges that filter; RLS still
 * scopes every statement by club, and INSERTs (persist) are never filtered.
 *
 * chosenScheduleId — le pointeur — est LE calendrier de la saison : choose() le
 * pose (valider), releaseSchedule() le retire (rouvrir). Rien ne le pose
 * automatiquement : seul le gestionnaire choisit.
 */
final class SchedulePlanProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
    ) {}

    /**
     * La clé de portée du verrou consultatif pour le plan SEASON d'une saison. SOURCE
     * UNIQUE : linkSchedule (schedule pas encore lié), planScopeOf et la validation/réouverture
     * doivent la calculer à l'IDENTIQUE — sinon le verrou cesse de coïncider et deux créations
     * ou validations concurrentes ne se sérialisent plus (uniq_schedule_plan_version, ou pire :
     * suppression des sœurs sous une validation parallèle). Un littéral en triple invitait la
     * divergence silencieuse.
     */
    public static function seasonScopeKey(string $seasonId): string
    {
        return 'season:' . $seasonId;
    }

    /** Inv. 9 : seuls closure/holiday portent un plan. Source unique du mapping. */
    private static function periodPlanType(?string $periodType): ?SchedulePlanType
    {
        return match ($periodType) {
            'closure' => SchedulePlanType::CLOSURE,
            'holiday' => SchedulePlanType::HOLIDAY,
            default => null,
        };
    }

    /**
     * The season's SEASON plan, created if absent. NOTE: an already-existing plan
     * is returned as a lazy ORM reference — read only its getId() unless the
     * request's active season IS this plan's season, else the proxy's filtered
     * lazy-load can throw EntityNotFoundException (season_filter). Lot A callers
     * either discard the result or use getId() only.
     */
    public function ensureSeasonPlan(Season $season): SchedulePlan
    {
        return $this->existingSeasonPlan($season->getId()) ?? $this->createSeasonPlan($season);
    }

    /**
     * ADR-0002 : la PÉRIODE du plan SEASON suit celle de la saison. Le NOM, lui,
     * appartient au plan (inv. 12) et n'est écrit que par son renommage — un
     * second écrivain le rendrait non durable.
     */
    public function syncSeasonPlan(Season $season): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET start_date = :start, end_date = :end, updated_at = now(), version = version + 1 '
            . 'WHERE season_id = :sid AND type = \'SEASON\'',
            [
                'start' => $season->getStartDate(),
                'end' => $season->getEndDate(),
                'sid' => $season->getId(),
            ],
            ['start' => Types::DATETIMETZ_IMMUTABLE, 'end' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * ADR-0002 C4 : NUMÉROTE une version fraîchement créée dans SON plan. Le plan est
     * DÉJÀ posé par l'appelant (`schedulePlanId`) — au POST (le client nomme le plan) ou
     * au regenerate (même plan que la source). linkSchedule ne le résout donc plus : il
     * pose seulement `versionNumber`, sous verrou, pour que deux créations concurrentes
     * ne collisionnent pas sur `uniq_schedule_plan_version`. No-op si non lié (anomalie)
     * ou déjà numéroté (idempotent). S'imbrique dans la transaction de l'appelant.
     */
    public function linkSchedule(Schedule $schedule): void
    {
        // Idempotence : une version déjà numérotée (≥ 1) est laissée telle quelle. Le plan
        // est non-nullable (lot D) — une version sans plan n'existe pas, donc pas de garde null.
        if (0 !== $schedule->getVersionNumber()) {
            return;
        }
        $planId = $schedule->getSchedulePlanId();

        $this->entityManager->wrapInTransaction(function () use ($schedule, $planId): void {
            // Même portée que la validation/réouverture (planScopeOf) : sinon le verrou
            // ne coïncide pas. PAS de flush avant nextVersionNumber (lot D) : le plan est
            // TOUJOURS déjà committé ici (ensureSeasonPlanId a sa propre transaction, le plan
            // de période naît en C1, un plan explicite est lu depuis la base) ; flusher
            // maintenant INSÉRERAIT la version avec version_number = 0. nextVersionNumber lit le
            // MAX des versions COMMITTÉES (la nouvelle, non flushée, n'y est pas → numéro correct).
            $this->lockPlanScope($this->planScopeOf($schedule));

            $versionNumber = $this->nextVersionNumber($planId);
            if (null === $versionNumber) {
                // Le plan a disparu entre sa résolution et ici (reset concurrent). LEVER : une
                // version non numérotée ne doit pas persister (version_number est NOT NULL, et
                // la sentinelle 0 n'est pas une vraie version). Le rollback annule la création.
                throw new LogicException(\sprintf('Le plan %s a disparu pendant la création de la version %s.', $planId, $schedule->getId()));
            }

            // Unique flush : la version est insérée avec schedule_plan_id ET version_number ≥ 1.
            $schedule->setVersionNumber($versionNumber);
            $this->entityManager->flush();
        });
    }

    /**
     * ADR-0002 inv. 1 — VALIDER = POINTER: the plan names this version as its
     * chosen one. "Validé" is derived from this pointer; there is no status.
     * Raw SQL: filter-free (the plan's season may not be the active one) and the
     * plan is not loaded in the UnitOfWork on the validation path. RLS scopes club.
     */
    public function choose(Schedule $schedule): bool
    {
        // ADR-0002 lot D : une version a TOUJOURS un plan (schedulePlanId non-nullable) — plus
        // de garde null, plus de self-heal. Le pointeur peut néanmoins pendre dans le vide (plan
        // supprimé sous ses pieds) : c'est le nombre de lignes touchées qui fait foi (ci-dessous).
        $planId = $schedule->getSchedulePlanId();

        // Le nombre de lignes touchées est la VÉRITÉ : un schedulePlanId non-null peut
        // pendre dans le vide (plan supprimé sous ses pieds). Le self-heal ci-dessus ne
        // rattrape que le cas null, jamais celui-là. Rendre true sur zéro ligne ferait
        // croire à l'appelant que la validation a eu lieu — or il supprime les versions
        // sœurs juste après : on les détruirait pour un pointeur jamais posé.
        // first_chosen_at : la PREMIÈRE validation du plan, posée une fois (COALESCE) et
        // jamais effacée — stat superadmin « temps de clôture » (création → 1re validation).
        $updated = $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = :sid, first_chosen_at = COALESCE(first_chosen_at, now()), updated_at = now(), version = version + 1 WHERE id = :pid',
            ['sid' => $schedule->getId(), 'pid' => $planId],
        );

        return $updated > 0;
    }

    /**
     * ADR-0002 : le plan SEASON d'une saison — LE calendrier de base. Exposé par
     * /api/me. `chosenScheduleId` = la version choisie (« validée ») ;
     * `hasFinishedVersion` = le plan porte au moins une version terminée
     * (déblocage cockpit + mode guidé, inv. 8/16) ;
     * `currentStructureHash` = le payload solver de la structure actuelle, pour
     * griser « Régénérer » quand la version sélectionnée est déjà à l'identique.
     *
     * SQL brut, comme le reste des lectures de plan ici : filter-free (la saison
     * demandée n'est pas forcément l'active) et une seule requête. RLS scope par club.
     *
     * @return array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool, currentStructureHash: string|null}|null
     */
    public function seasonPlanPayload(string $seasonId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT p.id, p.club_id, p.name, p.chosen_schedule_id, EXISTS ( '
            . 'SELECT 1 FROM schedule s WHERE s.schedule_plan_id = p.id '
            // Déverrouille le cockpit (inv. 8/16) : il faut une PREMIÈRE version
            // COMPLETED — décision fondateur. Un solve en échec ne donne aucun planning ;
            // envoyer le club au cockpit sur un FAILED l'y laisserait devant rien, alors
            // que sa place est dans le wizard, à corriger ses contraintes.
            // Indépendant du pointeur : avoir généré une fois suffit, choisir est un
            // autre geste — donc rouvrir ne re-verrouille jamais.
            . 'AND s.status = \'COMPLETED\') AS has_finished '
            . 'FROM schedule_plan p WHERE p.season_id = :sid AND p.type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        if (false === $row) {
            return null; // saison sans plan SEASON (donnée antérieure au lot A)
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'chosenScheduleId' => null === $row['chosen_schedule_id'] ? null : (string) $row['chosen_schedule_id'],
            'hasFinishedVersion' => (bool) $row['has_finished'],
            'currentStructureHash' => $this->currentStructureHash((string) $row['club_id'], $seasonId),
        ];
    }

    /**
     * La version CHOISIE du plan SEASON d'une saison — LE calendrier de base
     * (ADR-0002). null = espace de travail : le gestionnaire n'a rien choisi.
     * C'est la seule vérité : « validé » se dérive de ce pointeur.
     */
    public function chosenOfSeasonPlan(?string $seasonId): ?string
    {
        if (null === $seasonId) {
            return null;
        }

        $chosen = $this->entityManager->getConnection()->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return \is_string($chosen) ? $chosen : null;
    }

    /**
     * La version CHOISIE du plan d'une PÉRIODE (ADR-0002) — l'overlay validé de
     * l'entrée. null = plan non validé (espace de travail) : on n'expose alors
     * aucune version active (le cockpit route vers « Ajuster »). Miroir période de
     * {@see chosenOfSeasonPlan} ; source unique depuis le drop de overlayScheduleId.
     */
    public function chosenOfPeriodPlan(string $calendarEntryId): ?string
    {
        $chosen = $this->entityManager->getConnection()->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE calendar_entry_id = :eid',
            ['eid' => $calendarEntryId],
        );

        return \is_string($chosen) ? $chosen : null;
    }

    /**
     * Les versions choisies des plans de plusieurs périodes, en UNE requête — évite le
     * N+1 de {@see chosenOfPeriodPlan} en boucle (radar des matchs). Seuls les plans
     * VALIDÉS (chosenScheduleId non-null) apparaissent ; les autres sont absents de la carte.
     *
     * @param list<string> $calendarEntryIds
     *
     * @return array<string, string> calendarEntryId => chosenScheduleId
     */
    public function chosenByPeriodPlans(array $calendarEntryIds): array
    {
        if ([] === $calendarEntryIds) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT calendar_entry_id, chosen_schedule_id FROM schedule_plan WHERE calendar_entry_id IN (:ids) AND chosen_schedule_id IS NOT NULL',
            ['ids' => $calendarEntryIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['calendar_entry_id']] = (string) $row['chosen_schedule_id'];
        }

        return $map;
    }

    /** Cette version est-elle celle que pointe son plan ? (= « validée ») */
    public function isChosen(string $scheduleId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM schedule_plan WHERE chosen_schedule_id = :sid',
            ['sid' => $scheduleId],
        );
    }

    /**
     * ADR-0002 inv. 10 : le plan d'une période meurt avec la période elle-même.
     * Appelé UNIQUEMENT depuis la suppression de la CalendarEntry — surtout pas
     * depuis deleteOverlayForEntry, que la purge des périodes échues appelle sur des
     * entries qui survivent (leur plan nommé, et son compteur, doivent survivre).
     */
    public function deletePeriodPlan(string $calendarEntryId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($calendarEntryId): void {
            $this->lockPlanScope($calendarEntryId);
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM schedule_plan WHERE calendar_entry_id = :eid',
                ['eid' => $calendarEntryId],
            );
        });
    }

    /**
     * Sérialise tout ce qui touche au plan d'un scope (une période, ou la saison).
     * Verrou de TRANSACTION : relâché au commit, ré-entrant (le reprendre plus bas
     * dans la même transaction est un no-op).
     *
     * À prendre AVANT toute lecture qui décide (balayer les versions, résoudre le
     * plan) : le prendre après ne sérialise rien — la lecture a déjà eu lieu.
     */
    public function lockPlanScope(string $scope): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:scope))',
            ['scope' => 'schedule-plan-link:' . $scope],
        );
    }

    /**
     * A version is being removed OR reopened: if it is its plan's chosen version,
     * the plan loses its pointer and returns to "espace de travail" (inv. 2) — a
     * pointer must never name a deleted version. Raw SQL: filter-free, and the
     * plan is usually not in the UnitOfWork on these paths.
     */
    /**
     * Dépointe la version : le plan qui la nommait redevient un espace de travail.
     *
     * @return bool false si AUCUN plan ne la pointait — l'appelant ne doit alors pas
     *              annoncer une réouverture qui n'a pas eu lieu (une validation
     *              concurrente a pu déplacer le pointeur entre-temps)
     */
    public function releaseSchedule(string $scheduleId): bool
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = NULL, updated_at = now(), version = version + 1 '
            . 'WHERE chosen_schedule_id = :sid',
            ['sid' => $scheduleId],
        ) > 0;
    }

    /**
     * ADR-0002 inv. 5 + décision fondateur 2026-07-17 — LE PLAN NAÎT DU GESTE.
     *
     * Naissance SEULE : créer le plan de la période s'il n'existe pas, sinon rendre le
     * sien. Ni synchronisation, ni suppression — l'identité d'une période qui porte un
     * plan est GELÉE en amont (422, CalendarEntryStateProcessor), donc il n'y a jamais
     * rien à re-synchroniser ni à détruire ici. Supprimer la période reste le seul
     * chemin destructeur (deleteEntryAndCascade, derrière confirmation).
     *
     * Rend null quand l'entrée ne porte pas de plan par conception — inv. 9 :
     * cutoff/mutualisation restent des rappels calendrier.
     *
     * Sérialisé par période avec le même verrou consultatif que linkSchedule : un POST
     * double-soumis ne peut pas minter deux plans (check-then-insert). L'appelant doit
     * avoir FLUSHÉ l'entrée — la ligne est relue en SQL brut. S'imbrique sans risque dans
     * la transaction de l'appelant (le verrou tient jusqu'au commit le plus externe), ce
     * dont dépend l'atomicité entrée + plan.
     */
    public function provisionPeriodPlan(string $calendarEntryId): ?string
    {
        return $this->entityManager->wrapInTransaction(function () use ($calendarEntryId): ?string {
            $this->lockPlanScope($calendarEntryId);

            return $this->ensurePeriodPlanId($calendarEntryId);
        });
    }

    /**
     * Marque un plan comme configuré, au 1er réglage d'équipes écrit — le wizard s'en sert
     * pour ne seeder son défaut « Fanion seul » qu'une fois, et jamais après un retour
     * « tout actif » (0 override épars). Idempotent (`= false` dans le WHERE), et sans
     * effet si l'id ne désigne aucun plan.
     *
     * Vise le plan par son id depuis le lot C2 : les réglages y sont ancrés (inv. 5), plus
     * besoin de passer par le déclencheur calendrier.
     *
     * SQL brut, comme toute écriture de plan ici : `season_filter` épingle les lectures ORM
     * à la saison ACTIVE de la requête, or l'appelant reçoit l'id dans un corps de requête,
     * sans garantie qu'il appartienne à cette saison. RLS scope le club.
     */
    public function markPlanTeamSelectionInitialized(string $schedulePlanId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE schedule_plan SET team_selection_initialized = true, updated_at = now(), version = version + 1 '
            . 'WHERE id = :pid AND team_selection_initialized = false',
            ['pid' => $schedulePlanId],
        );
    }

    /** Cette période porte-t-elle un plan ? Garde du 422 d'identité (voir plus haut). */
    public function periodPlanExists(string $calendarEntryId): bool
    {
        return null !== $this->periodPlanId($calendarEntryId);
    }

    /**
     * Le plan d'une période, ou null si elle n'en porte pas (inv. 9). Public : les réglages
     * y étant ancrés (lot C2), les appelants qui partent du déclencheur calendrier
     * (validation, cascade de suppression) doivent le résoudre.
     */
    public function periodPlanId(string $calendarEntryId): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE calendar_entry_id = :eid',
            ['eid' => $calendarEntryId],
        );

        return false === $id ? null : (string) $id;
    }

    /**
     * ADR-0002 C4 — LA SEULE VÉRITÉ du « est-ce le socle ? » : plan.type === SEASON.
     * Remplace le doublon d'ancre nullable `null === schedule.calendarEntryId`.
     *
     * @throws LogicException une version SANS plan ne doit pas exister (ruling fondateur
     *                        2026-07-17). On LÈVE plutôt que de laisser un schedule non lié se faire passer
     *                        pour le socle — ce serait générer la saison avec les contraintes d'une période,
     *                        sans erreur ni signal (le piège d'ancre nullable de C2/C3, pour la 3e fois).
     */
    public function isSeasonSchedule(Schedule $schedule): bool
    {
        return SchedulePlanType::SEASON === $this->requirePlanRow($schedule)['type'];
    }

    /**
     * Un plan est-il de type SEASON ? Variante NON levante de isSeasonSchedule, pour les
     * chemins qui TOLÈRENT l'anomalie plutôt que de la bloquer — typiquement la
     * SUPPRESSION : un schedule sans plan (donc `false` ici) doit pouvoir être purgé, pas
     * lever un 500 (ruling 2026-07-17 : purger la donnée). Un plan absent → false.
     */
    public function planIsSeason(?string $planId): bool
    {
        return null !== $planId && SchedulePlanType::SEASON === ($this->fetchPlanRow($planId)['type'] ?? null);
    }

    /**
     * L'entrée-période à laquelle le plan du schedule est rattaché — null pour le plan
     * SEASON (qui pilote un build socle, pas un overlay). Remplace schedule.calendarEntryId
     * comme SOURCE de navigation (C4). Même contrat fail-loud que isSeasonSchedule().
     *
     * @throws LogicException quand le schedule ne porte aucun plan
     */
    public function periodEntryIdOf(Schedule $schedule): ?string
    {
        return $this->requirePlanRow($schedule)['calendarEntryId'];
    }

    /**
     * La portée du verrou consultatif d'un schedule : la période de son plan (SEASON → la
     * saison). Coïncide avec la clé prise par linkSchedule/validate/reopen. Le plan est
     * non-nullable (lot D) ; si sa ligne a disparu (reset concurrent), on retombe sur la
     * portée saison plutôt que de 500 un chemin de verrou.
     */
    public function planScopeOf(Schedule $schedule): string
    {
        $entryId = $this->fetchPlanRow($schedule->getSchedulePlanId())['calendarEntryId'] ?? null;

        return $entryId ?? self::seasonScopeKey($schedule->getSeasonId());
    }

    /**
     * ADR-0002 C4 : le contexte d'un plan pour VALIDER une création (POST /api/schedules
     * nomme le plan). Rend club/saison/type/déclencheur, ou null si le plan n'existe pas.
     * SQL brut : filter-free (la saison du plan n'est pas forcément l'active) ; RLS scope le
     * club (un plan d'un autre club rend null), et l'appelant re-check le club en défense.
     *
     * @return array{clubId: string, seasonId: string, type: SchedulePlanType, calendarEntryId: string|null}|null
     */
    public function fetchPlanContext(string $planId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT club_id, season_id, type, calendar_entry_id FROM schedule_plan WHERE id = :pid',
            ['pid' => $planId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'clubId' => (string) $row['club_id'],
            'seasonId' => (string) $row['season_id'],
            'type' => SchedulePlanType::from((string) $row['type']),
            'calendarEntryId' => null === $row['calendar_entry_id'] ? null : (string) $row['calendar_entry_id'],
        ];
    }

    /**
     * L'id du plan SEASON d'une saison, créé s'il manque (find-or-create). Public : le
     * processor le résout quand la création n'a pas nommé de plan (⇒ le socle). À POST, le
     * plan existe déjà (né avec la saison) ; l'"ensure" ne couvre qu'une donnée ancienne.
     *
     * Find-or-create SÉRIALISÉ sur la portée saison (même verrou consultatif que
     * linkSchedule/validate). Avant C4 cette résolution vivait DANS le verrou de linkSchedule ;
     * PR2 l'a sortie au processor. Sans le verrou ici, deux POST socle concurrents sur une
     * saison SANS plan (donnée antérieure au backfill) le créeraient tous deux et violeraient
     * uniq_schedule_plan_season_base (→ 500). Sous verrou, le 2e voit le plan committé du 1er.
     */
    public function ensureSeasonPlanId(string $seasonId): ?string
    {
        return $this->entityManager->wrapInTransaction(function () use ($seasonId): ?string {
            $this->lockPlanScope(self::seasonScopeKey($seasonId));

            $existing = $this->existingSeasonPlan($seasonId);
            if ($existing instanceof SchedulePlan) {
                return $existing->getId();
            }

            // Season owns no season_id column, so it is not season-filtered.
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);

            return $season instanceof Season ? $this->createSeasonPlan($season)->getId() : null;
        });
    }

    /**
     * E6 / correctif F2 : recale le nom des plans de FERMETURE une fois la datée `venue_closed`
     * connue (elle naît après l'entrée, 2 POST). Ne recale QUE les noms encore AUTO — un
     * renommage gestionnaire (inv. 12) ne suit pas le gabarit et n'est jamais écrasé. Recaler
     * même un auto DÉJÀ résolu couvre le RE-CIBLAGE du gymnase (Gym A → Gym B).
     *
     * Best-effort côté appelant (ConstraintStateProcessor l'entoure d'un try/catch) : un nom est
     * cosmétique et ne doit jamais faire échouer l'écriture de la contrainte, déjà committée.
     */
    public function refreshClosurePlanName(string $sourceEntryId): void
    {
        $venue = $this->closedVenueName($sourceEntryId);
        if (null === $venue) {
            return; // pas (encore) de gymnase résoluble : rien à recaler
        }

        // Le plan de la fermeture (mère) ET ceux de ses semaines enfants.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT p.id AS pid, p.name AS name, e.start_date, e.end_date FROM schedule_plan p '
            . 'JOIN calendar_entry e ON e.id = p.calendar_entry_id '
            . 'WHERE p.type = \'CLOSURE\' AND (e.id = :src OR e.parent_entry_id = :src)',
            ['src' => $sourceEntryId],
        );
        foreach ($rows as $r) {
            $current = (string) $r['name'];
            // Un nom AUTO suit toujours « Ajustement … du jj/mm/aaaa au jj/mm/aaaa » (générique OU
            // gymnase résolu). Un renommage gestionnaire ne colle pas à ce gabarit → protégé.
            if (1 !== preg_match('#^Ajustement .+ du \d{2}/\d{2}/\d{4} au \d{2}/\d{2}/\d{4}$#u', $current)) {
                continue;
            }
            $newName = $this->closurePlanName($venue, new DateTimeImmutable((string) $r['start_date']), new DateTimeImmutable((string) $r['end_date']));
            if ($newName === $current) {
                continue; // déjà à jour (gymnase inchangé)
            }
            // UPDATE brut : esquive le season_filter (le plan peut vivre dans une autre saison que
            // l'active — cf. le reste du provisioner), bump `version` à la main (pas de flush ORM →
            // pas d'OptimisticLock), et reste étanche (RLS scope par club).
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE schedule_plan SET name = :name, updated_at = now(), version = version + 1 WHERE id = :id',
                ['name' => $newName, 'id' => (string) $r['pid']],
            );
        }
    }

    /**
     * @throws LogicException le plan du schedule a disparu (reset concurrent)
     *
     * @return array{type: SchedulePlanType, calendarEntryId: string|null}
     */
    private function requirePlanRow(Schedule $schedule): array
    {
        // Le plan est non-nullable (lot D) : « sans plan » est inreprésentable. Reste le cas
        // où la ligne du plan a disparu sous les pieds (reset concurrent) → on lève.
        $planId = $schedule->getSchedulePlanId();
        $row = $this->fetchPlanRow($planId);
        if (null === $row) {
            throw new LogicException(\sprintf('Schedule %s points at plan %s which no longer exists.', $schedule->getId(), $planId));
        }

        return $row;
    }

    /** @return array{type: SchedulePlanType, calendarEntryId: string|null}|null */
    private function fetchPlanRow(string $planId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT type, calendar_entry_id FROM schedule_plan WHERE id = :pid',
            ['pid' => $planId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'type' => SchedulePlanType::from((string) $row['type']),
            'calendarEntryId' => null === $row['calendar_entry_id'] ? null : (string) $row['calendar_entry_id'],
        ];
    }

    private function currentStructureHash(string $clubId, string $seasonId): ?string
    {
        try {
            $payload = $this->constraintBuilder->buildForClubSeason($clubId, $seasonId);

            return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The season's SEASON plan if it already exists — persisted (raw SQL,
     * filter-free) OR still pending in the current unit of work (the raw-SQL
     * check cannot see an un-flushed INSERT, so a second provision in one UoW
     * would otherwise duplicate it and violate uniq_schedule_plan_season_base).
     */
    private function existingSeasonPlan(string $seasonId): ?SchedulePlan
    {
        $existingId = $this->findSeasonPlanId($seasonId);
        if (null !== $existingId) {
            // getReference, not find(): a season-filtered find() would return null
            // when the plan's season isn't the request's active one.
            $ref = $this->entityManager->getReference(SchedulePlan::class, $existingId);
            if ($ref instanceof SchedulePlan) {
                return $ref;
            }
        }

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof SchedulePlan
                && SchedulePlanType::SEASON === $entity->getType()
                && $entity->getSeasonId() === $seasonId) {
                return $entity;
            }
        }

        return null;
    }

    private function createSeasonPlan(Season $season): SchedulePlan
    {
        $plan = (new SchedulePlan)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setType(SchedulePlanType::SEASON)
            ->setName($this->seasonPlanName($season))
            ->setStartDate($season->getStartDate())
            ->setEndDate($season->getEndDate());
        $this->entityManager->persist($plan);

        return $plan;
    }

    private function ensurePeriodPlanId(string $calendarEntryId): ?string
    {
        $existingId = $this->periodPlanId($calendarEntryId);
        if (null !== $existingId) {
            return $existingId;
        }

        // Read the entry filter-free: season_filter would hide a period whose
        // season is not the request's active one.
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT club_id, season_id, title, period_type, start_date, end_date, parent_entry_id, school_holiday_id FROM calendar_entry WHERE id = :id',
            ['id' => $calendarEntryId],
        );
        if (false === $row) {
            return null;
        }

        $type = self::periodPlanType(\is_string($row['period_type']) ? $row['period_type'] : null);
        if (null === $type) {
            return null;
        }

        $plan = (new SchedulePlan)
            ->setClubId((string) $row['club_id'])
            ->setSeasonId((string) $row['season_id'])
            ->setType($type)
            ->setName($this->periodPlanName($type, $row, $calendarEntryId))
            ->setStartDate(new DateTimeImmutable((string) $row['start_date']))
            ->setEndDate(new DateTimeImmutable((string) $row['end_date']))
            ->setCalendarEntryId($calendarEntryId);
        $this->entityManager->persist($plan);

        return $plan->getId();
    }

    private function findSeasonPlanId(string $seasonId): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return false === $id ? null : (string) $id;
    }

    /**
     * ADR-0002 lot B1: the plan owns a MONOTONIC counter. MAX(version_number)+1
     * would REUSE a number once validation deletes the non-chosen versions (a
     * deleted V3, regenerated, would be V3 again). The UPDATE ... RETURNING is
     * atomic on its own; raw SQL also dodges the ORM season_filter (the plan's
     * season may not be the request's active one). RLS still scopes by club.
     */
    /**
     * ADR-0002 : compteur MONOTONE porté par le plan. Un `MAX+1` réattribuerait le
     * numéro d'une version supprimée (une V3 supprimée puis régénérée redeviendrait
     * V3). `GREATEST(compteur, MAX(version_number))` garde la monotonie ET
     * s'auto-répare : si le compteur dérivait sous le MAX réel (dump antérieur au
     * seed, plan recréé à la main), un compteur nu rendrait un numéro déjà pris et
     * chaque génération de ce plan échouerait à jamais sur uniq_schedule_plan_version.
     * SQL brut : atomique, et il esquive le season_filter (la saison du plan n'est
     * pas forcément l'active). RLS scope toujours par club.
     *
     * @return int|null null si le plan a disparu (course avec un reset de saison) — l'appelant
     *                  (linkSchedule) LÈVE alors : la version n'est pas numérotée et ne doit pas
     *                  persister (version_number est NOT NULL depuis le lot D)
     */
    private function nextVersionNumber(string $schedulePlanId): ?int
    {
        $next = $this->entityManager->getConnection()->fetchOne(
            'UPDATE schedule_plan p SET last_version_number = GREATEST( '
            . 'p.last_version_number, '
            . 'COALESCE((SELECT MAX(s.version_number) FROM schedule s WHERE s.schedule_plan_id = p.id), 0) '
            . ') + 1 WHERE p.id = :pid RETURNING p.last_version_number',
            ['pid' => $schedulePlanId],
        );

        // Zéro ligne : le plan a disparu entre la résolution et ici (reset concurrent) → null.
        // linkSchedule lève sur ce null (lot D : une version non numérotée ne persiste pas).
        return false === $next ? null : (int) $next;
    }

    private function seasonPlanName(Season $season): string
    {
        return 'Planning de la saison ' . $season->getName();
    }

    /**
     * E6 (types-de-planning « Nom par défaut ») : le nom PUBLIC du plan de période
     * (ADR-0002 inv. 12) — la RÉPONSE, distincte du FAIT déclencheur (`CalendarEntry.title`,
     * ex. « Gymnase A — fermé »). Source unique côté serveur ; le gestionnaire renomme ensuite.
     * - CLOSURE : « Ajustement {gymnase} du {début} au {fin} » (gymnase = la datée `venue_closed`).
     * - HOLIDAY : « Planning de {label} du {début} au {fin} » (label = « Vacances de la Toussaint »
     *   → « Planning de vacances de la Toussaint … »).
     * Fallback sobre si la donnée manque (gymnase inconnu / vacances hors référentiel) — jamais de crash.
     *
     * ⚠ CLOSURE : la datée `venue_closed` naît APRÈS l'entrée (le front fait 2 POST) ; à la
     * naissance du plan le gymnase est donc souvent introuvable → nom générique « Ajustement
     * gymnase … ». {@see refreshClosurePlanName} le recale quand la datée arrive.
     *
     * @param array<string, mixed> $row colonnes lues dans ensurePeriodPlanId
     */
    private function periodPlanName(SchedulePlanType $type, array $row, string $calendarEntryId): string
    {
        $start = new DateTimeImmutable((string) $row['start_date']);
        $end = new DateTimeImmutable((string) $row['end_date']);
        // Les datées ET le rattachement vacances d'une semaine ENFANT vivent sur sa MÈRE
        // (datedConstraintSourceId = parent_entry_id ?? id).
        $source = \is_string($row['parent_entry_id'] ?? null) ? (string) $row['parent_entry_id'] : $calendarEntryId;

        if (SchedulePlanType::HOLIDAY === $type) {
            // Un enfant de semaine ne porte pas school_holiday_id : remonter à la mère.
            $holidayId = \is_string($row['school_holiday_id'] ?? null) ? (string) $row['school_holiday_id'] : $this->holidayIdOf($source);

            return $this->holidayPlanName($holidayId, $start, $end);
        }

        // CLOSURE : la datée venue_closed naît APRÈS l'entrée (2 POST), donc le gymnase n'est
        // JAMAIS résoluble ici — nom générique direct, sans requête inutile. refreshClosurePlanName
        // le recale dès que la datée arrive (ConstraintStateProcessor::afterPersist).
        return $this->closurePlanName(null, $start, $end);
    }

    private function holidayIdOf(string $entryId): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT school_holiday_id FROM calendar_entry WHERE id = :id',
            ['id' => $entryId],
        );

        return \is_string($id) && '' !== $id ? $id : null;
    }

    private function holidayPlanName(?string $holidayId, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $label = null;
        if (null !== $holidayId) {
            $found = $this->entityManager->getConnection()->fetchOne(
                'SELECT label FROM school_holiday_period WHERE id = :id',
                ['id' => $holidayId],
            );
            $label = \is_string($found) && '' !== $found ? $found : null;
        }
        // « Vacances de la Toussaint » → « Planning de vacances de la Toussaint … ».
        $name = null === $label
            ? 'Planning de vacances ' . $this->windowSuffix($start, $end)
            : 'Planning de ' . lcfirst($label) . ' ' . $this->windowSuffix($start, $end);

        return mb_substr($name, 0, 180);
    }

    /**
     * Nom du gymnase fermé = scopeTargetId de la datée `venue_closed`. Filtré sur le TYPE
     * (F5 : FACILITY couvre aussi forced/preferred/forbidden Venue — un autre FACILITY daté
     * ne doit pas être pris) ; `created_at` pour un choix déterministe s'il y en a plusieurs.
     */
    private function closedVenueName(string $sourceEntryId): ?string
    {
        $name = $this->entityManager->getConnection()->fetchOne(
            'SELECT v.name FROM venue v JOIN "constraint" c ON c.scope_target_id = v.id '
            . 'WHERE c.calendar_entry_id = :eid AND c.family = :fam AND c.is_active = true '
            . 'AND (c.config->>\'type\' = \'venue_closed\' OR c.config->>\'type\' IS NULL) '
            . 'ORDER BY c.created_at ASC LIMIT 1',
            ['eid' => $sourceEntryId, 'fam' => ConstraintFamily::FACILITY->value],
        );

        return \is_string($name) ? $name : null;
    }

    private function closurePlanName(?string $venue, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return mb_substr('Ajustement ' . ($venue ?? 'gymnase') . ' ' . $this->windowSuffix($start, $end), 0, 180);
    }

    private function windowSuffix(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return 'du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y');
    }
}
