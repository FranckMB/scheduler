<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Exception\ScheduleGenerationInProgressException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

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
 */
final class MoveSlotService
{
    /** Contrat backend⇄engine du endpoint de validation (F2a). Un seul contrat, 3 endpoints. */
    private const string CONTRACT_VERSION = '2.7';

    /**
     * Budget COURT : la baseline est entièrement figée, le moteur ne place qu'UN candidat
     * déjà épinglé (cible UX 2-3 s). Le schéma engine plafonne à 10 s.
     */
    private const int VALIDATE_TIMEOUT_SECONDS = 2;

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
     * @throws InvalidArgumentException              le créneau n'a pas de planning parent (état incohérent)
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string}>}
     */
    public function move(ScheduleSlotTemplate $slot, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId): array
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

        // (2)+(3) baseline figée SANS la source → verdict moteur sur le candidat.
        $payload = $this->buildValidationPayload($schedule, $slot, $dayOfWeek, $startTime, $venueId);
        $result = $this->engineClient->validateAssignment($payload, self::VALIDATE_TIMEOUT_SECONDS);

        if (true !== ($result['valid'] ?? false)) {
            // (4) Le déplacement N'A PAS LIEU — les règles violées sont rendues nommées.
            return ['valid' => false, 'violations' => $this->namedViolations($result)];
        }

        // (5) Écrire le déplacement, poser le marqueur (score désormais périmé), publier.
        $slot
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            ->setVenueId($venueId);
        $schedule->setManuallyEditedSinceGeneration(true);
        $this->entityManager->flush();

        // Les autres gestionnaires voient le planning bouger (best-effort, comme la génération).
        $this->progressPublisher->publishSafely($schedule, []);

        return ['valid' => true, 'violations' => []];
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
     *    planning (moins la source) et les réservations durables — jamais ceux d'un frère.
     *
     * @return array<string, mixed>
     */
    private function buildValidationPayload(Schedule $schedule, ScheduleSlotTemplate $slot, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId): array
    {
        $overlayEntryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        $overlayEntry = null === $overlayEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($overlayEntryId);

        $payload = $overlayEntry instanceof CalendarEntry
            ? $this->constraintBuilder->buildForOverlay($schedule, $overlayEntry)
            : $this->constraintBuilder->buildForClubSeason($schedule->getClubId(), $schedule->getSeasonId());

        $currentSlotTemplates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
        $payload['slotTemplates'] = $this->baselineWithoutSourceAndSiblings($currentSlotTemplates, $schedule, $slot);
        // Le bloc `implicitRules` (P2-28) est émis par `buildForClubSeason`/`buildForOverlay`, mais
        // le schéma `/validate-assignments` ne le porte PAS (extra=forbid → 422). Le verdict d'un
        // déplacement valide un candidat contre la baseline HARD ; les règles bien-être y jouent
        // avec leurs défauts moteur, sans réglage transmis. On le retire donc du payload de verdict.
        unset($payload['implicitRules']);
        $payload['version'] = self::CONTRACT_VERSION;
        $payload['solverTimeoutSeconds'] = self::VALIDATE_TIMEOUT_SECONDS;
        $payload['candidate'] = [
            'teamId' => $slot->getTeamId(),
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime->format('H:i'),
            // La durée est CELLE de la séance déplacée (le déplacement la préserve).
            'durationMinutes' => $slot->getDurationMinutes(),
        ];

        return $payload;
    }

    /**
     * Retire de la baseline sérialisée la source ET tout créneau d'une AUTRE version du
     * plan de saison — en gardant réservations et créneaux de CE planning. On identifie
     * les « frères » par leur id de `ScheduleSlotTemplate` (une réservation n'y figure
     * pas), donc filtrer sur cet ensemble laisse intacts les pins durables.
     *
     * @param array<int, mixed> $slotTemplates
     *
     * @return array<int, mixed>
     */
    private function baselineWithoutSourceAndSiblings(array $slotTemplates, Schedule $schedule, ScheduleSlotTemplate $slot): array
    {
        $sourceId = $slot->getId();

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

        $excluded = [$sourceId => true];
        foreach ($rows as $row) {
            $excluded[$row['id']] = true;
        }

        return array_values(array_filter(
            $slotTemplates,
            static fn (mixed $entry): bool => !\is_array($entry) || !isset($excluded[(string) ($entry['id'] ?? '')]),
        ));
    }

    /**
     * Les règles violées, réduites à ce que l'UI exploite : un code de règle (pour brancher)
     * et un message déjà humain (le moteur y nomme coach/gymnase/heure). Rien d'autre ne
     * quitte le moteur — les ids internes restent côté serveur.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array{rule: string, message: string}>
     */
    private function namedViolations(array $result): array
    {
        $raw = $result['violations'] ?? null;
        if (!\is_array($raw)) {
            $this->logger->warning('Engine returned an invalid verdict with no violations array.');

            return [['rule' => 'unknown_hard_conflict', 'message' => 'Ce déplacement casse une règle du moteur qui n\'a pas pu être nommée.']];
        }

        $violations = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $violations[] = [
                'rule' => (string) ($entry['rule'] ?? 'unknown_hard_conflict'),
                'message' => (string) ($entry['message'] ?? 'Ce déplacement casse une règle du moteur.'),
            ];
        }

        return $violations;
    }
}
