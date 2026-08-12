<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Entity\TeamTag;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleResultImporter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — LE PLANNING PÉRIMÉ PAR UNE RESSOURCE (axe §7.1 : planning lifecycle).
 *
 * Troisième jumeau de `ConstraintChangeStaleScheduleTest` : une contrainte n'est pas la seule
 * entrée du solveur. Un gymnase, un coach, un créneau/grille, une réservation, un override de
 * période, un tag d'équipe ou une entrée de calendrier qui change APRÈS génération rend le
 * planning PÉRIMÉ — il décrit un état antérieur des DONNÉES, sans que rien ne le dise.
 *
 * L'invariant gardé ici, en base (pas au code retour) :
 *   - une ressource CLUB+SAISON modifiée marque les plannings COMPLETED du club+saison ;
 *   - un tag d'équipe (club-level, sans saison) marque les plannings du club ;
 *   - **le cœur du lot, ADR-0002 : la grille SAISON (créneau sans plan) ne périme QUE le plan
 *     SEASON, jamais les COPIES de période ; un créneau d'un plan de PÉRIODE ne périme QUE ce
 *     plan** — le périmètre se dérive de la colonne `schedule_plan_id`, mécaniquement ;
 *   - un override de période marque SON plan seul, pas le socle ;
 *   - un import de résultat solveur DÉMARQUE ;
 *   - la frontière saison tient ; un planning non COMPLETED n'est pas marqué ;
 *   - la LISTE FERMÉE : une écriture d'entité de RÉSULTAT (schedule_diagnostic, pipeline) ne
 *     marque RIEN — un marquage trop large use la bannière jusqu'à ce qu'on l'ignore ;
 *   - **la ressource écrite HORS API (EntityManager nu) marque quand même** — la preuve que
 *     c'est le listener d'entité qui attrape tout writer, jamais un appelant nommé.
 */
#[Group('phase1')]
#[Group('integration')]
final class ResourceChangeStaleScheduleTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleResultImporter $importer;

    public function testAResourceWrittenOutsideTheApiMarksCompletedSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        self::assertFalse($this->reload($schedule)->isResourcesChangedSinceGeneration());

        // ⚑ Chemin NON-API : on renomme le gymnase directement par l'EntityManager, comme le
        // ferait un import FFBB ou une commande. Si le marquage n'était branché que sur un
        // processeur d'API, ce cas resterait à false — et le bug reviendrait en silence.
        $this->renameVenueViaEntityManager($club, $season);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une ressource modifiée (même hors API) doit marquer les plannings générés comme périmés.',
        );
    }

    public function testATeamTagMarksTheClubSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        // Un tag d'équipe est club-level (pas de saison) : re-dériver un tag déplace la portée
        // d'une contrainte, donc périme. On marque le club entier (repli assumé, cf. listener).
        $tag = (new TeamTag)->setClubId($club->getId())->setName('Compétition');
        $this->em->persist($tag);
        $this->em->flush();

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un tag d\'équipe modifié périme les plannings du club (sa portée a pu bouger).',
        );
    }

    public function testASeasonGridChangeMarksTheSeasonPlanNotThePeriodPlan(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $this->em->flush();
        // Le montage lui-même écrit des ressources (gymnase, entrée de calendrier) qui marquent
        // légitimement : on repart d'une ardoise propre pour ne mesurer QUE le geste sous test.
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Un créneau de la grille SAISON (schedule_plan_id NULL, ADR-0002 : la grille de la
        // période est une COPIE, jamais une union). Il ne doit périmer QUE le socle.
        $this->trainingSlot($club, $season, $venueId, null);

        self::assertTrue(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Un créneau de la grille SAISON périme le plan SEASON.',
        );
        self::assertFalse(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'ADR-0002 : la grille SAISON ne périme PAS un plan de période (sa grille est une copie).',
        );
    }

    public function testAPeriodGridChangeMarksThatPlanNotTheSeasonPlan(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $periodPlanId = $periodSchedule->getSchedulePlanId();
        $this->em->flush();
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Un créneau PRÊTÉ au plan de période (schedule_plan_id = ce plan). Il ne doit périmer
        // QUE ce plan — inversement du test précédent, c'est la moitié « et inversement » d'ADR-0002.
        $this->trainingSlot($club, $season, $venueId, $periodPlanId);

        self::assertTrue(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'Un créneau du plan de période périme CE plan.',
        );
        self::assertFalse(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'ADR-0002 : un créneau de période ne périme PAS le socle de saison.',
        );
    }

    public function testAPeriodOverrideMarksItsPlanOnly(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $periodPlanId = $periodSchedule->getSchedulePlanId();
        $this->em->flush();
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Un override de période porte TOUJOURS un schedule_plan_id (celui de sa période).
        $override = (new VenuePeriodOverride)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($periodPlanId)
            ->setVenueId($venueId);
        $this->em->persist($override);
        $this->em->flush();

        self::assertTrue(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'Un override de période périme le plan de sa période.',
        );
        self::assertFalse(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Un override de période ne périme pas le socle de saison.',
        );
    }

    public function testAnImportClearsTheStaleMarker(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        $this->renameVenueViaEntityManager($club, $season);
        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());

        // Un résultat solveur a été résolu contre les DONNÉES COURANTES → plus périmé.
        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning (il redevient fidèle aux données).',
        );
    }

    public function testAnotherSeasonPlanIsNotMarked(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        $this->renameVenueViaEntityManager($club, $season);

        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : un gymnase de la saison N ne périme pas un plan de la saison N+1.',
        );
    }

    public function testANonCompletedScheduleIsNotMarked(): void
    {
        [$club, $season] = $this->seed();
        $draft = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Brouillon')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($draft);
        $this->em->flush();

        $this->renameVenueViaEntityManager($club, $season);

        self::assertFalse(
            $this->reload($draft)->isResourcesChangedSinceGeneration(),
            'Un planning non généré (DRAFT) n\'a aucun résultat à périmer — on ne le marque pas.',
        );
    }

    public function testAResultEntityWriteMarksNothing(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        // Entité de RÉSULTAT du pipeline (liste fermée) : écrire un diagnostic ne périme rien —
        // c'est une SORTIE du solveur, pas une donnée qu'il consomme. Un marquage ici userait
        // la bannière à chaque génération.
        $diagnostic = (new ScheduleDiagnostic)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())
            ->setType('INFEASIBLE')
            ->setSeverity(ScheduleDiagnosticSeverity::WARNING)
            ->setMessage('exemple');
        $this->em->persist($diagnostic);
        $this->em->flush();

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une écriture d\'entité de résultat (pipeline) ne doit RIEN marquer (liste fermée).',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(ScheduleResultImporter::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = $this->season($club, '2025-2026', '2025-09-01', '2026-06-30');

        return [$club, $season];
    }

    private function season(Club $club, string $name, string $start, string $end): Season
    {
        $season = (new Season)->setClubId($club->getId())->setName($name)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        // Une vraie saison naît avec son plan SEASON (les 4 chemins de création le posent).
        $this->provisionSeasonPlan($season);

        return $season;
    }

    private function venue(Club $club, Season $season): string
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    private function seasonSchedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    /** Un planning COMPLETED d'un plan de PÉRIODE (holiday), via une CalendarEntry. */
    private function periodSchedule(Club $club, Season $season): Schedule
    {
        $entry = (new CalendarEntry)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)
            ->setTitle('Vacances de la Toussaint')
            ->setStartDate(new DateTimeImmutable('2025-10-18'))
            ->setEndDate(new DateTimeImmutable('2025-11-02'));
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Sp')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());

        return $schedule;
    }

    private function trainingSlot(Club $club, Season $season, string $venueId, ?string $schedulePlanId): void
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setVenueId($venueId)
            ->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setCapacity(1)
            ->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Renomme le gymnase via l'EntityManager NU — le chemin d'une commande ou d'un import, PAS
     * le processeur d'API. C'est la falsification qui prouve la couverture du listener d'entité.
     */
    private function renameVenueViaEntityManager(Club $club, Season $season): void
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase renommé')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->em->clear();
    }

    /** Ardoise propre : remet les marqueurs à false pour isoler le seul geste sous test. */
    private function resetMarkers(Schedule ...$schedules): void
    {
        // clear() AVANT de relire : un bulk UPDATE (le listener) ne synchronise pas l'identity
        // map — sans ça on relirait l'instance périmée (rc encore false en mémoire) et le
        // setter serait un no-op qui laisserait le true en base.
        $this->em->clear();
        foreach ($schedules as $schedule) {
            $managed = $this->em->find(Schedule::class, $schedule->getId());
            self::assertInstanceOf(Schedule::class, $managed);
            $managed->setResourcesChangedSinceGeneration(false);
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function reload(Schedule $schedule): Schedule
    {
        $this->em->clear();
        $found = $this->em->find(Schedule::class, $schedule->getId());
        self::assertInstanceOf(Schedule::class, $found);

        return $found;
    }
}
