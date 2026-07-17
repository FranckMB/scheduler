<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
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
     * ADR-0002 lot C — le plan de période naît DU GESTE (provisionPeriodPlan), plus
     * de la première version. linkSchedule ne fait plus que s'y raccrocher.
     */
    /**
     * NR lot C — linkSchedule ne CRÉE JAMAIS un plan de période : il n'existe qu'un
     * seul site de naissance (le geste). Un second créateur laisserait passer
     * inaperçu un plan manquant à la création de la période, et les réglages saisis
     * avant la 1re génération n'auraient rien à quoi s'accrocher (inv. 5).
     */
    public function testLinkScheduleNeverCreatesAPeriodPlan(): void
    {
        $clubId = $this->seedClub();
        $season = $this->makeSeason($clubId);
        $entry = $this->makeClosureEntry($clubId, $season->getId()); // pas de geste rejoué

        $overlay = $this->makeSchedule($clubId, $season->getId(), $entry->getId());
        $this->provisioner->linkSchedule($overlay);
        $this->em->flush();

        self::assertNull(
            $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $entry->getId()]),
            'linkSchedule ne doit pas fabriquer le plan a posteriori.',
        );
        self::assertNull($overlay->getSchedulePlanId(), 'sans plan, la version reste non liée plutôt que rattachée à un plan inventé.');
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
        self::assertSame('Fermeture gymnase', $periodPlan->getName());
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
        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setName('Version');
        $schedule->setStatus(ScheduleStatus::DRAFT);
        $schedule->setCalendarEntryId($calendarEntryId);
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
