<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Exception\DurationMismatchException;
use App\Exception\EvictTargetLockedException;
use App\Exception\EvictTargetMismatchException;
use App\Exception\ScheduleGenerationInProgressException;
use App\Exception\SlotUnavailableException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Déplacer un créneau SOUS LE VERDICT DU MOTEUR (P2-2 F2b).
 *
 * Le geste de déplacement passait par le rail `manual-edit/one-time`, qui n'inspecte que
 * les chevauchements bruts — jamais capacité, fenêtres ni repos. Un gestionnaire pouvait
 * donc déplacer une équipe vers un créneau illégal sans que rien ne l'arrête. Ici, dans
 * l'ordre : (1) refus 409 si une génération tourne, (2) baseline figée SANS la source,
 * (3) verdict moteur (`POST /validate-assignments`, contrat F2a), (4) si « non » les
 * règles violées NOMMÉES pour l'UI, (5) si « oui » l'écriture + le marqueur « retouché à
 * la main » + la publication Mercure.
 *
 * ⚠ La re-validation a lieu AU MOMENT D'ÉCRIRE (décision fondateur) : le verdict n'est pas
 * un cache. Chaque appel reconstruit la baseline courante et redemande au moteur.
 *
 * P2-30 PR A — deux gestes de plus, MÊME rail (verdict souverain, écriture sous « oui ») :
 *  - {@see move()} accepte une ÉVICTION (`evictSlotId`) : retirer l'occupant de la cible, sous D3
 *    (un occupant verrouillé refuse l'éviction avant tout appel moteur) — suppression dans la
 *    même transaction que le déplacement ;
 *  - {@see place()} CRÉE une séance à la dérive (surnuméraire / rattrapage) : pas de source, la
 *    baseline reste complète, et la ligne naît déverrouillée.
 */
final class MoveSlotService
{
    /** Contrat backend⇄engine du endpoint de validation (F2a). Un seul contrat, 3 endpoints. */
    private const string CONTRACT_VERSION = '2.11';

    /**
     * Budget SOLVEUR court PAR solve : la baseline est entièrement figée, le moteur ne place
     * qu'UN candidat déjà épinglé. C'est la valeur portée par `solverTimeoutSeconds` du payload.
     */
    private const int VALIDATE_TIMEOUT_SECONDS = 2;

    /**
     * Timeout HTTP de l'appel `/validate-assignments` — DISTINCT du budget solveur : depuis P2-32
     * un candidat ACCEPTÉ déclenche JUSQU'À TROIS solves côté moteur (le verdict + les deux états
     * figés « avant »/« après » du DELTA de compromis), chacun sous son budget de 2 s. Le timeout
     * transport doit donc les couvrir tous — un `timeout` calé sur le seul verdict rendait un 502
     * sur un dataset dense (ex. BCCL, 90 séances) alors que le moteur répondait juste après.
     */
    private const int VALIDATE_HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubGenerationLock $clubGenerationLock,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly EngineClient $engineClient,
        private readonly ScheduleProgressPublisher $progressPublisher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws EvictTargetMismatchException          l'occupant à évincer ne siège pas à la cible (→ 422)
     * @throws EvictTargetLockedException            l'occupant à évincer est verrouillé, D3 (→ 422)
     * @throws TransportExceptionInterface           le moteur n'a pas répondu (→ 502)
     * @throws InvalidArgumentException              le créneau n'a pas de planning parent (état incohérent)
     *
     * `$dryRun` (P2-32) : même chemin JUSQU'AU VERDICT INCLUS (gardes pré-moteur comprises — un
     * verrou refuse l'essai, D3), puis retour AVANT toute écriture/flush/marqueur/Mercure. La
     * réponse porte alors `dryRun => true` et, si une éviction était demandée, l'état qui SERAIT
     * évincé (`evicted`, sans suppression).
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>, compromises: list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>, dryRun?: bool, evicted?: array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}}
     */
    public function move(ScheduleSlotTemplate $slot, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId, ?string $evictSlotId = null, bool $dryRun = false): array
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($slot->getScheduleId());
        if (!$schedule instanceof Schedule) {
            throw new InvalidArgumentException('The slot has no parent schedule.');
        }

        // (1) Une génération réécrit le planning : déplacer maintenant écraserait un
        //     résultat en vol. On CONSTATE l'état sans toucher au verrou (sonde de lecture).
        if ($this->clubGenerationLock->isGenerating($slot->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // (1bis) Éviction éventuelle : on VALIDE la cible AVANT tout appel moteur (D3 : un verrou
        //        est souverain, un occupant verrouillé fait échouer ici sans jamais consulter le moteur).
        $evicted = null === $evictSlotId
            ? null
            : $this->resolveEvictionTarget($slot, $schedule, $evictSlotId, $dayOfWeek, $startTime, $venueId);

        // (2)+(3) baseline figée SANS la source (ni l'occupant évincé) → verdict moteur sur le candidat.
        $payload = $this->buildBasePayload($schedule, $slot, $evicted);
        $payload['candidate'] = [
            'teamId' => $slot->getTeamId(),
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime->format('H:i'),
            // La durée est CELLE de la séance déplacée (le déplacement la préserve) — côté serveur,
            // jamais du client.
            'durationMinutes' => $slot->getDurationMinutes(),
        ];
        // P2-32 — l'état « AVANT » du candidat pour le DELTA de compromis : le placement d'ORIGINE
        // de la source (elle est retirée de la baseline, cf. buildBasePayload). Lu du slot AVANT
        // toute mutation. Une CRÉATION (place()) n'en pose pas → « avant » = baseline nue.
        $payload['reference'] = [
            'teamId' => $slot->getTeamId(),
            'venueId' => $slot->getVenueId(),
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startTime' => $slot->getStartTime()->format('H:i'),
            'durationMinutes' => $slot->getDurationMinutes(),
        ];
        $result = $this->engineClient->validateAssignment($payload, self::VALIDATE_HTTP_TIMEOUT_SECONDS);

        if (true !== ($result['valid'] ?? false)) {
            // (4) Le déplacement N'A PAS LIEU (ni l'éviction) — les règles violées sont rendues nommées.
            $refused = ['valid' => false, 'violations' => $this->namedViolations($result), 'compromises' => []];
            if ($dryRun) {
                $refused['dryRun'] = true;
            }

            return $refused;
        }

        $compromises = $this->namedCompromises($result);

        // (dryRun) Le verdict est rendu (compromis compris), MAIS rien n'est écrit : ni le
        // déplacement, ni la suppression de l'occupant, ni le marqueur, ni Mercure. L'`evicted`
        // décrit l'état qui SERAIT évincé (sans le supprimer).
        if ($dryRun) {
            $dryResponse = ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'dryRun' => true];
            if ($evicted instanceof ScheduleSlotTemplate) {
                $dryResponse['evicted'] = $this->describeEvicted($evicted);
            }

            return $dryResponse;
        }

        // (5) Écrire le déplacement, supprimer l'occupant évincé (MÊME transaction), poser le
        //     marqueur (score désormais périmé), publier.
        $slot
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            ->setVenueId($venueId);

        $response = ['valid' => true, 'violations' => [], 'compromises' => $compromises];
        if ($evicted instanceof ScheduleSlotTemplate) {
            // Capturer l'état d'AVANT suppression : le front s'en sert pour proposer de replacer.
            $response['evicted'] = $this->describeEvicted($evicted);
            $this->entityManager->remove($evicted);
        }
        $schedule->setManuallyEditedSinceGeneration(true);
        $this->entityManager->flush();

        // Les autres gestionnaires voient le planning bouger (best-effort, comme la génération).
        $this->progressPublisher->publishSafely($schedule, []);

        return $response;
    }

    /**
     * PLACER une séance À LA DÉRIVE (P2-30) sous le verdict — création, pas déplacement. Il n'y a
     * pas de source : la baseline reste COMPLÈTE (moins les frères d'une autre version du plan).
     * Refus → RIEN créé ; accepté → une ligne `ScheduleSlotTemplate` DÉVERROUILLÉE (lockLevel
     * NONE, lockOrigin null, coachId null), le marqueur « retouché », la publication Mercure.
     *
     * ⚠ Aucune garde de comptage ici : placer une séance surnuméraire reste permis — le verdict
     * moteur est le seul juge de la légalité (capacité, fenêtre, repos coach…).
     *
     * ⚠ La DURÉE ne vient JAMAIS du client : elle est résolue de la fenêtre de gymnase visée, dans
     * le MÊME payload que le moteur (le solveur ne lit pas `candidate.durationMinutes`, il dérive
     * tout de la fenêtre — un client qui posterait 600 min sur une fenêtre de 90 écrirait sinon une
     * occupation jamais validée). `$clientDurationMinutes` n'est donc qu'une ASSERTION : s'il ment
     * → 422 `duration_mismatch` ; s'il est null → la fenêtre fait foi.
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws SlotUnavailableException              aucune fenêtre de gymnase à ce créneau (→ 422)
     * @throws DurationMismatchException             la durée du client contredit la fenêtre (→ 422)
     * @throws TransportExceptionInterface           le moteur n'a pas répondu (→ 502)
     *
     * `$dryRun` (P2-32) : même chemin JUSQU'AU VERDICT INCLUS (résolution de durée/fenêtre
     * comprise), puis retour AVANT toute écriture — aucune ligne créée, aucun marqueur, aucun
     * Mercure ; la réponse porte le verdict, ses compromis et `dryRun => true`
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>, compromises: list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>, dryRun?: bool, slotId?: string}
     */
    public function place(Schedule $schedule, string $teamId, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId, ?int $clientDurationMinutes = null, bool $dryRun = false): array
    {
        if ($this->clubGenerationLock->isGenerating($schedule->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // Baseline + fenêtres de gymnase, AVANT tout appel moteur. La durée se résout ici, de la
        // fenêtre — jamais du client.
        $payload = $this->buildBasePayload($schedule, null, null);

        $durationMinutes = $this->resolveWindowDurationMinutes($payload, $venueId, $dayOfWeek, $startTime);
        if (null === $durationMinutes) {
            // Aucune fenêtre à (gymnase, jour, heure) : le créneau n'existe pas. Rien à faire juger,
            // aucune durée à propager — on tranche avant l'appel.
            throw new SlotUnavailableException;
        }
        if (null !== $clientDurationMinutes && $clientDurationMinutes !== $durationMinutes) {
            // Le client a menti sur la durée de la fenêtre : refus avant le moteur, rien écrit.
            throw new DurationMismatchException;
        }

        $payload['candidate'] = [
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime->format('H:i'),
            'durationMinutes' => $durationMinutes,
        ];
        $result = $this->engineClient->validateAssignment($payload, self::VALIDATE_HTTP_TIMEOUT_SECONDS);

        if (true !== ($result['valid'] ?? false)) {
            $refused = ['valid' => false, 'violations' => $this->namedViolations($result), 'compromises' => []];
            if ($dryRun) {
                $refused['dryRun'] = true;
            }

            return $refused;
        }

        $compromises = $this->namedCompromises($result);

        // (dryRun) Verdict rendu, RIEN créé : aucune ligne, aucun marqueur, aucun Mercure.
        if ($dryRun) {
            return ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'dryRun' => true];
        }

        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            // La durée PERSISTÉE est celle de la fenêtre (résolue serveur), pas celle du client.
            ->setDurationMinutes($durationMinutes);
        // Déverrouillée par construction : lockLevel NONE / lockOrigin null / coachId null (défauts
        // d'entité). L'API ne pose JAMAIS un verrou ici — c'est le rail manual-edit/lock qui le fait.
        $this->entityManager->persist($slot);
        $schedule->setManuallyEditedSinceGeneration(true);
        $this->entityManager->flush();

        $this->progressPublisher->publishSafely($schedule, []);

        return ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'slotId' => $slot->getId()];
    }

    /**
     * L'état d'un occupant à évincer, tel que le front s'en sert pour proposer un replacement.
     *
     * @return array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}
     */
    private function describeEvicted(ScheduleSlotTemplate $evicted): array
    {
        return [
            'slotId' => $evicted->getId(),
            'teamId' => $evicted->getTeamId(),
            'dayOfWeek' => $evicted->getDayOfWeek(),
            'startTime' => $evicted->getStartTime()->format('H:i'),
            'venueId' => $evicted->getVenueId(),
            'durationMinutes' => $evicted->getDurationMinutes(),
        ];
    }

    /**
     * Valide et retourne le créneau OCCUPANT désigné par `evictSlotId`, ou lève. TOUTE la
     * validation a lieu AVANT le moteur : introuvable / d'un autre planning / égal à la source /
     * ne siégeant pas à la cible → {@see EvictTargetMismatchException} ; verrouillé →
     * {@see EvictTargetLockedException} (D3 : un verrou est souverain, le moteur n'est pas appelé).
     */
    private function resolveEvictionTarget(ScheduleSlotTemplate $source, Schedule $schedule, string $evictSlotId, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId): ScheduleSlotTemplate
    {
        $evicted = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->find($evictSlotId);
        if (!$evicted instanceof ScheduleSlotTemplate
            || $evicted->getScheduleId() !== $schedule->getId()
            || $evicted->getId() === $source->getId()) {
            throw new EvictTargetMismatchException;
        }

        // L'occupant doit SIÉGER à la cible : même gymnase + même jour + chevauchement horaire
        // avec la fenêtre du candidat [startTime, startTime + durée du candidat[.
        if ($evicted->getVenueId() !== $venueId
            || $evicted->getDayOfWeek() !== $dayOfWeek
            || !$this->overlaps($startTime, $source->getDurationMinutes(), $evicted->getStartTime(), $evicted->getDurationMinutes())) {
            throw new EvictTargetMismatchException;
        }

        // D3 — un verrou est souverain, quelle que soit son origine : on n'évince jamais un
        // créneau verrouillé sans qu'il soit déverrouillé d'abord.
        if (LockLevel::NONE !== $evicted->getLockLevel()) {
            throw new EvictTargetLockedException;
        }

        return $evicted;
    }

    /** Deux fenêtres [start, start+durée[ (minutes depuis minuit) se chevauchent-elles ? */
    private function overlaps(DateTimeImmutable $startA, int $durationA, DateTimeImmutable $startB, int $durationB): bool
    {
        $aStart = (int) $startA->format('G') * 60 + (int) $startA->format('i');
        $bStart = (int) $startB->format('G') * 60 + (int) $startB->format('i');

        return $aStart < $bStart + $durationB && $bStart < $aStart + $durationA;
    }

    /**
     * Le payload `/validate-assignments` : le vocabulaire de `/generate` (venues/teams/
     * coaches/constraints/slotTemplates) + un `candidate` + la version du contrat.
     *
     * ⚠ Deux retraits sur la baseline, sinon un déplacement PARFAITEMENT LÉGAL serait
     * refusé (le piège signalé par F2a) :
     *  - la SOURCE : sans elle, l'équipe entre en conflit AVEC ELLE-MÊME
     *    (`team_no_overlap` / `one_session_per_day`) — elle serait figée mardi ET candidate
     *    jeudi ;
     *  - les créneaux des AUTRES versions du même plan : `buildForClubSeason` fige toutes
     *    les versions du plan de saison ; l'équipe se heurterait à son propre placement
     *    dans un brouillon voisin. On ne garde donc de la baseline QUE les créneaux de CE
     *    planning (moins les ids exclus) et les réservations durables — jamais ceux d'un frère.
     *
     * `$source` (déplacement) et `$evicted` (occupant à évincer) sont retirés de la baseline
     * en plus des frères ; à la CRÉATION (place), les deux sont null → baseline complète moins
     * les frères.
     *
     * Le `candidate` n'est PAS posé ici : chaque appelant l'ajoute une fois sa durée résolue
     * CÔTÉ SERVEUR (le déplacement la tient du slot ; la création la lit de la fenêtre de gymnase
     * de CE payload — jamais du client). Le payload rendu porte déjà les fenêtres (`venues`).
     *
     * @return array<string, mixed>
     */
    private function buildBasePayload(Schedule $schedule, ?ScheduleSlotTemplate $source, ?ScheduleSlotTemplate $evicted): array
    {
        $overlayEntryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        $overlayEntry = null === $overlayEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($overlayEntryId);

        $payload = $overlayEntry instanceof CalendarEntry
            ? $this->constraintBuilder->buildForOverlay($schedule, $overlayEntry)
            : $this->constraintBuilder->buildForClubSeason($schedule->getClubId(), $schedule->getSeasonId());

        $excludedIds = [];
        if ($source instanceof ScheduleSlotTemplate) {
            $excludedIds[] = $source->getId();
        }
        if ($evicted instanceof ScheduleSlotTemplate) {
            $excludedIds[] = $evicted->getId();
        }

        $currentSlotTemplates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
        $payload['slotTemplates'] = $this->baselineWithoutSiblings($currentSlotTemplates, $schedule, $excludedIds);
        // Le bloc `implicitRules` (P2-28) reste dans le payload : parité génération ⇄ verdict —
        // un geste sur un planning généré en PREFERRED doit être jugé au MÊME réglage,
        // sinon le verdict tout-HARD refuserait à tort ce que la génération a permis.
        $payload['version'] = self::CONTRACT_VERSION;
        $payload['solverTimeoutSeconds'] = self::VALIDATE_TIMEOUT_SECONDS;

        return $payload;
    }

    /**
     * La durée d'une séance NE VIENT JAMAIS DU CLIENT : c'est celle de la fenêtre de gymnase visée
     * (venueId + dayOfWeek + startTime), lue dans le MÊME payload que le moteur — `venues[].trainingSlots`
     * dérive de `VenueTrainingSlot`, exactement la source dont le solveur tire `slot_durations`.
     * Aucune fenêtre à ce triplet → null (le créneau n'existe pas).
     *
     * @param array<string, mixed> $payload
     */
    private function resolveWindowDurationMinutes(array $payload, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime): ?int
    {
        $venues = \is_array($payload['venues'] ?? null) ? $payload['venues'] : [];
        $wanted = $startTime->format('H:i');

        foreach ($venues as $venue) {
            if (!\is_array($venue) || ($venue['id'] ?? null) !== $venueId) {
                continue;
            }
            $slots = \is_array($venue['trainingSlots'] ?? null) ? $venue['trainingSlots'] : [];
            foreach ($slots as $slot) {
                if (\is_array($slot)
                    && ($slot['dayOfWeek'] ?? null) === $dayOfWeek
                    && ($slot['startTime'] ?? null) === $wanted) {
                    return (int) $slot['durationMinutes'];
                }
            }
        }

        return null;
    }

    /**
     * Retire de la baseline sérialisée les ids exclus (source et/ou occupant évincé) ET tout
     * créneau d'une AUTRE version du plan de saison — en gardant réservations et créneaux de CE
     * planning. On identifie les « frères » par leur id de `ScheduleSlotTemplate` (une
     * réservation n'y figure pas), donc filtrer sur cet ensemble laisse intacts les pins durables.
     *
     * @param array<int, mixed> $slotTemplates
     * @param list<string>      $excludedIds
     *
     * @return array<int, mixed>
     */
    private function baselineWithoutSiblings(array $slotTemplates, Schedule $schedule, array $excludedIds): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id')
            ->from(ScheduleSlotTemplate::class, 's')
            ->where('s.clubId = :clubId')
            ->andWhere('s.seasonId = :seasonId')
            ->andWhere('s.scheduleId <> :scheduleId')
            ->setParameter('clubId', $schedule->getClubId())
            ->setParameter('seasonId', $schedule->getSeasonId())
            ->setParameter('scheduleId', $schedule->getId())
            ->getQuery()
            ->getScalarResult();

        $excluded = [];
        foreach ($excludedIds as $id) {
            $excluded[$id] = true;
        }
        foreach ($rows as $row) {
            $excluded[$row['id']] = true;
        }

        return array_values(array_filter(
            $slotTemplates,
            static fn (mixed $entry): bool => !\is_array($entry) || !isset($excluded[(string) ($entry['id'] ?? '')]),
        ));
    }

    /**
     * Les règles violées, réduites à ce que l'UI exploite : un code de règle (pour brancher),
     * un message déjà humain (le moteur y nomme coach/gymnase/heure) ET les ids des entités
     * fautives, tels que le moteur les émet — pour surligner le conflit côté front. Ce ne sont
     * pas des ids « internes » cachés : ils désignent des entités que le client possède déjà
     * (ses équipes/coachs/gymnases). `conflictingTeamId` porte l'équipe DÉJÀ en place qui
     * bloque le candidat. Chaque champ est null-safe : absent du verdict → null, jamais une
     * clé manquante.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>
     */
    private function namedViolations(array $result): array
    {
        $raw = $result['violations'] ?? null;
        if (!\is_array($raw)) {
            $this->logger->warning('Engine returned an invalid verdict with no violations array.');

            return [[
                'rule' => 'unknown_hard_conflict',
                'message' => 'Ce déplacement casse une règle du moteur qui n\'a pas pu être nommée.',
                'teamId' => null, 'coachId' => null, 'venueId' => null,
                'dayOfWeek' => null, 'startTime' => null, 'conflictingTeamId' => null,
            ]];
        }

        $violations = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $violations[] = [
                'rule' => (string) ($entry['rule'] ?? 'unknown_hard_conflict'),
                'message' => (string) ($entry['message'] ?? 'Ce déplacement casse une règle du moteur.'),
                'teamId' => $this->nullableString($entry['teamId'] ?? null),
                'coachId' => $this->nullableString($entry['coachId'] ?? null),
                'venueId' => $this->nullableString($entry['venueId'] ?? null),
                'dayOfWeek' => isset($entry['dayOfWeek']) ? (int) $entry['dayOfWeek'] : null,
                'startTime' => $this->nullableString($entry['startTime'] ?? null),
                'conflictingTeamId' => $this->nullableString($entry['conflictingTeamId'] ?? null),
            ];
        }

        return $violations;
    }

    /**
     * Les COMPROMIS d'un candidat ACCEPTÉ (P2-32), réduits à ce que l'UI exploite (patron
     * {@see namedViolations}) : la famille (pour brancher), l'effet (`broken`/`gained`), un
     * message déjà humain (le moteur y nomme équipe/coach/gymnase, AUCUN identifiant interne) et
     * les ids des entités concernées, pour le surlignage. Chaque champ est null-safe. Absent du
     * verdict (refus, ou moteur muet) → liste vide, jamais une clé manquante.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>
     */
    private function namedCompromises(array $result): array
    {
        $raw = $result['compromises'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $compromises = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $compromises[] = [
                'family' => (string) ($entry['family'] ?? ''),
                'effect' => (string) ($entry['effect'] ?? ''),
                'message' => (string) ($entry['message'] ?? ''),
                'teamId' => $this->nullableString($entry['teamId'] ?? null),
                'coachId' => $this->nullableString($entry['coachId'] ?? null),
                'venueId' => $this->nullableString($entry['venueId'] ?? null),
                'dayOfWeek' => isset($entry['dayOfWeek']) ? (int) $entry['dayOfWeek'] : null,
                'startTime' => $this->nullableString($entry['startTime'] ?? null),
            ];
        }

        return $compromises;
    }

    /** Passe l'id tel quel (string) ou null s'il est absent/null — jamais un cast d'array/objet. */
    private function nullableString(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}
