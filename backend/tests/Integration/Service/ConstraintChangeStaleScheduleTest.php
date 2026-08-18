<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleResultImporter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — LE PLANNING PÉRIMÉ PAR UNE CONTRAINTE (axe §7.1 : planning lifecycle).
 *
 * Le bug de confiance : passer une contrainte en HARD après coup, puis regarder le planning
 * d'avant (résolu quand la règle était encore soft). Le moteur avait raison de ne rien dire —
 * le résultat n'est pas FAUX, il est PÉRIMÉ. L'application mentait par omission en l'affichant
 * comme s'il décrivait les données courantes.
 *
 * L'invariant gardé ici, en base (pas au code retour) :
 *   - modifier une contrainte APRÈS génération marque les plannings COMPLETED du club+saison ;
 *   - un import de résultat solveur DÉMARQUE (le planning redevient fidèle aux données) ;
 *   - un planning VALIDÉ / en vigueur est marqué comme les autres (c'est le plus grave) ;
 *   - la frontière saison tient (un autre plan de saison n'est pas marqué) ;
 *   - un planning non COMPLETED n'est pas marqué (rien à périmer) ;
 *   - **la contrainte écrite HORS de l'API (EntityManager nu, comme une commande) marque
 *     quand même** — la preuve que c'est le listener d'entité qui attrape tout writer, jamais
 *     un appelant nommé qu'on aurait pu oublier.
 */
#[Group('phase1')]
#[Group('integration')]
final class ConstraintChangeStaleScheduleTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleResultImporter $importer;

    public function testAConstraintWrittenOutsideTheApiMarksCompletedSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->completedSchedule($club, $season);
        $this->em->flush();

        // Neuf : rien n'a encore changé.
        self::assertFalse($this->reload($schedule)->isConstraintsChangedSinceGeneration());

        // ⚑ Chemin NON-API : on écrit la contrainte directement par l'EntityManager, comme le
        // ferait une commande ou un import. Si le marquage n'était branché que sur le
        // processeur d'API, ce cas resterait à false — et le bug reviendrait en silence.
        $this->writeConstraintViaEntityManager($club, $season);

        self::assertTrue(
            $this->reload($schedule)->isConstraintsChangedSinceGeneration(),
            'Une contrainte modifiée (même hors API) doit marquer les plannings générés comme périmés.',
        );
    }

    public function testAValidatedInForcePlanIsMarkedLikeTheOthers(): void
    {
        [$club, $season] = $this->seed();
        // choosePlanVersion pointe le plan sur cette version = « validée » / en vigueur.
        $schedule = $this->validatedSchedule($season);

        $this->writeConstraintViaEntityManager($club, $season);

        self::assertTrue(
            $this->reload($schedule)->isConstraintsChangedSinceGeneration(),
            'Un planning validé périmé est PLUS grave, pas moins : c\'est celui dont le gestionnaire se sert.',
        );
    }

    public function testAnImportClearsTheStaleMarker(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->completedSchedule($club, $season);
        $this->em->flush();

        $this->writeConstraintViaEntityManager($club, $season);
        self::assertTrue($this->reload($schedule)->isConstraintsChangedSinceGeneration());

        // Un résultat solveur a été résolu contre les règles COURANTES → plus périmé.
        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isConstraintsChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning (il redevient fidèle aux données).',
        );
    }

    public function testAnotherSeasonPlanIsNotMarked(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->completedSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->completedSchedule($club, $otherSeason);
        $this->em->flush();

        $this->writeConstraintViaEntityManager($club, $season);

        self::assertTrue($this->reload($schedule)->isConstraintsChangedSinceGeneration());
        self::assertFalse(
            $this->reload($otherSchedule)->isConstraintsChangedSinceGeneration(),
            'La frontière saison tient : une contrainte de la saison N ne périme pas un plan de la saison N+1.',
        );
    }

    public function testANonCompletedScheduleIsNotMarked(): void
    {
        [$club, $season] = $this->seed();
        $draft = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Brouillon')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($draft);
        $this->em->flush();

        $this->writeConstraintViaEntityManager($club, $season);

        self::assertFalse(
            $this->reload($draft)->isConstraintsChangedSinceGeneration(),
            'Un planning non généré (DRAFT) n\'a aucun résultat à périmer — on ne le marque pas.',
        );
    }

    /**
     * P2-38 — une fermeture datée est TRANSVERSALE. Le marqueur de fraîcheur reste club+saison
     * (surmarquage conservateur ASSUMÉ par le fondateur) : une datée portée par l'entrée A périme
     * bien le COMPLETED du plan de l'entrée B, dont la fenêtre recoupe la fermeture. C'est le
     * comportement souhaité — un « périmé » de trop coûte une régénération, un « périmé » manqué
     * coûte la confiance.
     */
    public function testADatedClosureOnAnotherEntryMarksAPeriodPlanCompleted(): void
    {
        [$club, $season] = $this->seed();

        // Entrée A (porteuse de la fermeture) et entrée B (dont on teste le plan COMPLETED).
        $entryA = $this->periodEntry($club, $season, '2026-05-01', '2026-05-31');
        $entryB = $this->periodEntry($club, $season, '2026-05-04', '2026-05-10');
        $scheduleB = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Overlay B')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($scheduleB, $entryB->getId());
        $this->em->flush();

        self::assertFalse($this->reload($scheduleB)->isConstraintsChangedSinceGeneration(), 'neuf : rien n’a changé');

        // Une fermeture datée `venue_closed` portée par l'ENTRÉE A, écrite via l'EntityManager nu.
        $closure = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Gym en travaux')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($entryA->getId());
        $closure->setConfig(['type' => 'venue_closed', 'startDate' => '2026-05-05', 'endDate' => '2026-05-05']);
        $this->em->persist($closure);
        $this->em->flush();
        $this->em->clear();

        self::assertTrue(
            $this->reload($scheduleB)->isConstraintsChangedSinceGeneration(),
            'une fermeture portée par l’entrée A périme le COMPLETED du plan de l’entrée B (surmarquage conservateur assumé).',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(ScheduleResultImporter::class);
    }

    private function periodEntry(Club $club, Season $season, string $start, string $end): CalendarEntry
    {
        $entry = (new CalendarEntry)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)
            ->setTitle('Période ' . $start)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
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

        return $season;
    }

    private function completedSchedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    private function validatedSchedule(Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($season->getClubId())->setSeasonId($season->getId())->setName('S validé')->setStatus(ScheduleStatus::COMPLETED);
        // Le plan pointe cette version : « validée » / en vigueur, donc en lecture seule côté UI.
        $this->choosePlanVersion($schedule);

        return $schedule;
    }

    /**
     * Écrit une contrainte permanente via l'EntityManager NU — le chemin d'une commande ou
     * d'un import, PAS le processeur d'API. C'est la falsification qui prouve la couverture
     * du listener d'entité.
     */
    private function writeConstraintViaEntityManager(Club $club, Season $season): void
    {
        $constraint = (new Constraint)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('SM2 au moins 1 séance à Matéo')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig([]);
        $this->em->persist($constraint);
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
