<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\SchoolHolidayPeriod;
use App\Entity\Season;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ADR-0002 Lot A NR (structuring axis: planning lifecycle §7.1). The
 * SchedulePlanProvisioner is the SINGLE point that creates plans and links
 * versions — this proves the going-forward mapping (the migration backfill
 * mirrors the same logic), under RLS tenant scoping.
 */
#[Group('phase1')]
#[Group('integration')]
final class SchedulePlanProvisionerTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private SchedulePlanProvisioner $provisioner;

    public function testSeasonGetsAnEmptySeasonPlan(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);

        $plan = $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        self::assertSame(SchedulePlanType::SEASON, $plan->getType());
        self::assertSame($clubId, $plan->getClubId());
        self::assertSame($season->getId(), $plan->getSeasonId());
        self::assertSame('Planning de la saison 2025-2026', $plan->getName());
        self::assertSame($season->getStartDate()->format('Y-m-d'), $plan->getStartDate()->format('Y-m-d'));
        self::assertNull($plan->getCalendarEntryId());
        self::assertNull($plan->getChosenScheduleId());

        // Idempotent: a second call returns the same row, no duplicate.
        $again = $this->provisioner->ensureSeasonPlan($season);
        self::assertSame($plan->getId(), $again->getId());
        self::assertCount(1, $this->em->getRepository(SchedulePlan::class)->findBy(['seasonId' => $season->getId()]));
    }

    public function testDoubleProvisionInOneUnitOfWorkDoesNotDuplicate(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);

        // Two provisions before any flush: the raw-SQL existence check can't see
        // the first (un-flushed) INSERT, so the pending-UoW guard must catch it —
        // otherwise the flush violates uniq_schedule_plan_season_base.
        $a = $this->provisioner->ensureSeasonPlan($season);
        $b = $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        self::assertSame($a->getId(), $b->getId());
        self::assertCount(1, $this->em->getRepository(SchedulePlan::class)->findBy(['seasonId' => $season->getId()]));
    }

    public function testSeasonPlanIsResyncedWhenTheSeasonIsEdited(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $plan = $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        $named = $fresh = $this->em->getRepository(SchedulePlan::class)->find($plan->getId());
        self::assertInstanceOf(SchedulePlan::class, $named);
        $named->setName('Saison définitive');
        $season->setStartDate(new DateTimeImmutable('2025-08-15'));
        $season->setEndDate(new DateTimeImmutable('2026-07-10'));
        $this->em->flush();

        $this->provisioner->syncSeasonPlan($season);

        $this->em->clear();
        $fresh = $this->em->getRepository(SchedulePlan::class)->find($plan->getId());
        self::assertInstanceOf(SchedulePlan::class, $fresh);
        self::assertSame('2025-08-15', $fresh->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-07-10', $fresh->getEndDate()->format('Y-m-d'));
        // Le nom vit sur le plan (inv. 12) : une édition de la saison recale les
        // DATES et ne doit jamais réécrire le nom que le gestionnaire a choisi.
        self::assertSame('Saison définitive', $fresh->getName(), 'un resync de saison n\'écrase pas le nom du plan');
    }

    public function testSeasonSchedulesLinkAsIncrementingVersions(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        $v1 = $this->makeSchedule($clubId, $season->getId(), null);
        $this->provisioner->linkSchedule($v1);
        $this->em->flush();

        $v2 = $this->makeSchedule($clubId, $season->getId(), null);
        $this->provisioner->linkSchedule($v2);
        $this->em->flush();

        $seasonPlan = $this->em->getRepository(SchedulePlan::class)->findOneBy([
            'seasonId' => $season->getId(),
            'type' => SchedulePlanType::SEASON,
        ]);
        self::assertInstanceOf(SchedulePlan::class, $seasonPlan);
        self::assertSame($seasonPlan->getId(), $v1->getSchedulePlanId());
        self::assertSame($seasonPlan->getId(), $v2->getSchedulePlanId());
        self::assertSame(1, $v1->getVersionNumber());
        self::assertSame(2, $v2->getVersionNumber());
    }

    /**
     * NR lot C — linkSchedule ne CRÉE JAMAIS un plan de période : il n'existe qu'un seul site
     * de naissance (le geste). lot D : une version sans plan est INREPRÉSENTABLE ; une période
     * sans geste n'a donc simplement AUCUN plan auquel rattacher une version — on le prouve par
     * l'absence de plan (et non plus par une version « non liée », qui ne peut plus exister).
     */
    public function testAPeriodWithoutTheGestureHasNoPlanToLinkTo(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $entry = $this->makeClosureEntry($clubId, $season->getId()); // pas de geste rejoué

        self::assertNull(
            $this->provisioner->periodPlanId($entry->getId()),
            'sans le geste, la période n\'a pas de plan : linkSchedule n\'en fabrique jamais un a posteriori, et aucune version ne peut s\'y rattacher.',
        );
    }

    public function testOverlayScheduleLinksToThePlanBornWithTheGesture(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $entry = $this->makeClosureEntry($clubId, $season->getId());
        // En prod, CalendarEntryStateProcessor le fait au POST ; l'entrée est fabriquée
        // à la main ici, on rejoue donc le geste.
        $this->provisioner->provisionPeriodPlan($entry->getId());

        $overlay = $this->makeSchedule($clubId, $season->getId(), $entry->getId());
        $this->provisioner->linkSchedule($overlay);
        $this->em->flush();

        $periodPlan = $this->em->getRepository(SchedulePlan::class)->findOneBy([
            'calendarEntryId' => $entry->getId(),
        ]);
        self::assertInstanceOf(SchedulePlan::class, $periodPlan);
        self::assertSame(SchedulePlanType::CLOSURE, $periodPlan->getType());
        // E6 : le nom du PLAN est la RÉPONSE (« Ajustement … du … au … »), pas le FAIT
        // déclencheur (`entry.title`). Aucune datée venue_closed seedée ici → fallback « gymnase ».
        self::assertSame('Ajustement gymnase du 20/10/2025 au 26/10/2025', $periodPlan->getName());
        self::assertSame($entry->getId(), $periodPlan->getCalendarEntryId());
        self::assertSame($periodPlan->getId(), $overlay->getSchedulePlanId());
        self::assertSame(1, $overlay->getVersionNumber());
    }

    public function testLinksOverlayEvenWhenSeasonFilterPinsAnotherSeason(): void
    {
        // Regression: the provisioner must not be scoped by the request
        // season_filter — an overlay binds to its period's season, which may
        // differ from the active one. With the filter pinned elsewhere, the
        // existing-plan lookup must still find/link (no duplicate, no 500).
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $entry = $this->makeClosureEntry($clubId, $season->getId());
        $this->provisioner->provisionPeriodPlan($entry->getId()); // le geste

        // Pin the ORM season_filter to a DIFFERENT (fake) season.
        $filters = $this->em->getFilters();
        $filters->enable('season_filter')->setParameter('season_id', $this->fakeUuid(), 'guid');

        try {
            $v1 = $this->makeSchedule($clubId, $season->getId(), $entry->getId());
            $this->provisioner->linkSchedule($v1);
            $this->em->flush();

            $v2 = $this->makeSchedule($clubId, $season->getId(), $entry->getId());
            $this->provisioner->linkSchedule($v2);
            $this->em->flush();
        } finally {
            $filters->disable('season_filter');
        }

        // Both versions link to the SAME single period plan — no duplicate.
        $plans = $this->em->getRepository(SchedulePlan::class)->findBy(['calendarEntryId' => $entry->getId()]);
        self::assertCount(1, $plans);
        self::assertSame($plans[0]->getId(), $v1->getSchedulePlanId());
        self::assertSame($plans[0]->getId(), $v2->getSchedulePlanId());
        self::assertSame(1, $v1->getVersionNumber());
        self::assertSame(2, $v2->getVersionNumber());
    }

    public function testTwoSeasonsDoNotShareASeasonPlan(): void
    {
        $clubId = $this->seedClub();
        $seasonA = $this->makeSeason($clubId);
        $seasonB = $this->makeSeason($clubId, '2026-2027', '2026-09-01', '2027-06-30');

        $planA = $this->provisioner->ensureSeasonPlan($seasonA);
        $planB = $this->provisioner->ensureSeasonPlan($seasonB);

        self::assertNotSame($planA->getId(), $planB->getId());
        self::assertSame($seasonA->getId(), $planA->getSeasonId());
        self::assertSame($seasonB->getId(), $planB->getSeasonId());
    }

    /**
     * NR E6 (axe planning lifecycle §7.1 — identité du plan). Le plan de FERMETURE porte
     * le nom-réponse « Ajustement {gymnase} du … au … », le gymnase venant de la datée
     * venue_closed (pas de `entry.title`).
     */
    public function testClosurePlanIsNamedAfterTheClosedVenueAndWindow(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $entry = $this->makeClosureEntry($clubId, $season->getId());
        $this->seedClosedVenueConstraint($clubId, $season->getId(), $entry->getId(), 'Gymnase Barros');

        $this->provisioner->provisionPeriodPlan($entry->getId());
        $plan = $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $entry->getId()]);

        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('Ajustement Gymnase Barros du 20/10/2025 au 26/10/2025', $plan->getName());
    }

    /**
     * NR E6. Le plan de REPRISE porte « Planning de {label vacances} du … au … » — le label
     * du référentiel (« Vacances de la Toussaint ») en minuscule d'attaque donne la phrase cible.
     */
    public function testHolidayPlanIsNamedAfterTheHolidayLabelAndWindow(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $holiday = $this->seedSchoolHoliday('Vacances de la Toussaint');
        $entry = $this->makeHolidayEntry($clubId, $season->getId(), $holiday->getId());

        $this->provisioner->provisionPeriodPlan($entry->getId());
        $plan = $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $entry->getId()]);

        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
        self::assertSame('Planning de vacances de la Toussaint du 20/10/2025 au 02/11/2025', $plan->getName());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
    }

    private function fakeUuid(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }

    private function seedClosedVenueConstraint(string $clubId, string $seasonId, string $entryId, string $venueName): void
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName($venueName);
        $venue->setSource('manual');
        $this->em->persist($venue);

        $constraint = new Constraint;
        $constraint->setClubId($clubId);
        $constraint->setSeasonId($seasonId);
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId($venue->getId());
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Salle fermée');
        $constraint->setConfig(['type' => 'venue_closed']);
        $constraint->setCalendarEntryId($entryId);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    private function seedSchoolHoliday(string $label): SchoolHolidayPeriod
    {
        $holiday = new SchoolHolidayPeriod;
        $holiday->setZone('A');
        $holiday->setLabel($label);
        $holiday->setHolidayType('toussaint');
        $holiday->setSchoolYear('2025-2026');
        $holiday->setStartDate(new DateTimeImmutable('2025-10-20'));
        $holiday->setEndDate(new DateTimeImmutable('2025-11-02'));
        $this->em->persist($holiday);
        $this->em->flush();

        return $holiday;
    }

    private function makeHolidayEntry(string $clubId, string $seasonId, string $schoolHolidayId): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($clubId);
        $entry->setSeasonId($seasonId);
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Reprise Toussaint');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-11-02'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setSchoolHolidayId($schoolHolidayId);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function seedClub(): string
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club plan ' . $uid);
        $club->setSlug('club-plan-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PLN' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        return $club->getId();
    }

    private function makeSeason(
        string $clubId,
        string $name = '2025-2026',
        string $start = '2025-09-01',
        string $end = '2026-06-30',
    ): Season {
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName($name);
        $season->setStartDate(new DateTimeImmutable($start));
        $season->setEndDate(new DateTimeImmutable($end));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function makeSchedule(string $clubId, string $seasonId, ?string $calendarEntryId): Schedule
    {
        // Depuis C4 le schedule ne porte plus calendarEntryId : la version pend au PLAN, et
        // c'est l'appelant qui POSE schedulePlanId (linkSchedule ne fait plus que numéroter).
        // lot D : le plan est obligatoire (non-nullable) — plan SEASON si version de saison,
        // sinon le plan de la période (qui DOIT exister : sans lui, aucune version n'est créable).
        $planId = null === $calendarEntryId
            ? $this->provisioner->ensureSeasonPlanId($seasonId)
            : $this->provisioner->periodPlanId($calendarEntryId);
        self::assertIsString($planId, 'seed: la version doit avoir un plan (lot D)');

        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setName('Version');
        $schedule->setStatus(ScheduleStatus::DRAFT);
        $schedule->setSchedulePlanId($planId); // AVANT persist (schedule_plan_id NOT NULL)
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function makeClosureEntry(string $clubId, string $seasonId): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($clubId);
        $entry->setSeasonId($seasonId);
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Fermeture gymnase');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(true);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
