<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ADR-0002 inv. 2 — reopening the version a plan POINTS at un-points it: the plan
 * becomes an "espace de travail" again and the version is editable. Only the chosen
 * version can be reopened, and only within the caller's own club.
 */
#[Group('phase1')]
#[Group('integration')]
final class ReopenScheduleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testReopenUnpointsTheChosenVersion(): void
    {
        [$user, , $season] = $this->seed('REO1');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($schedule);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/reopen");

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->chosenPlanVersion($season), 'the plan is an espace de travail again');
        // The version survives, untouched: reopening drops the pointer, not the work.
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($schedule->getId())?->getStatus());
    }

    public function testReopenKeepsTheCockpitUnlocked(): void
    {
        // inv. 8/16: the cockpit unlocks on "≥1 finished version", NOT on the
        // pointer — reopening drops the pointer but the version remains, so the
        // manager must not be thrown back into the guided mode.
        [$user, , $season] = $this->seed('REO5');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($schedule);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/reopen");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $plan = self::getContainer()->get(\App\Service\SchedulePlanProvisioner::class)->seasonPlanPayload($season->getId());
        self::assertTrue($plan['hasFinishedVersion'], 'reopen must NOT re-lock the cockpit');
        self::assertNull($plan['chosenScheduleId']);
    }

    public function testReopenBaselineWithOverlaysRequiresConfirm(): void
    {
        [$user, , $season] = $this->seed('REO6');
        $baseline = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($baseline);
        $this->overlayEntry($season, 'Vacances Toussaint');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$baseline->getId()}/reopen");

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $data['code']);
        self::assertSame(1, $data['count']);
        self::assertSame('Vacances Toussaint', $data['overlays'][0]['title']);
    }

    public function testReopenBaselineWithConfirmDeletesOverlays(): void
    {
        [$user, $club, $season] = $this->seed('REO7');
        $baseline = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($baseline);
        [$entry, $overlayId, $slotId, $datedId] = $this->overlayEntry($season, 'Toussaint');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$baseline->getId()}/reopen", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        // Overlay schedule + its slots purged; entry + dated constraint kept, link reset.
        self::assertNull($this->em->getRepository(Schedule::class)->find($overlayId));
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId));
        $reloadedEntry = $this->em->getRepository(CalendarEntry::class)->find($entry->getId());
        self::assertNotNull($reloadedEntry);
        self::assertNull($reloadedEntry->getOverlayScheduleId());
        self::assertNotNull($this->em->getRepository(Constraint::class)->find($datedId), 'dated constraint is kept (period stays signalée)');
    }

    public function testReopenLeavesASiblingVersionAndItsOverlaysAlone(): void
    {
        // A plan points at ONE version; a sibling is just an unchosen version.
        // Reopening the chosen one must not disturb the sibling — only the
        // overlays, which hang off the base plan being un-pointed.
        [$user, , $season] = $this->seed('REO8');
        $baseline = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($baseline);
        $sibling = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$baseline->getId()}/reopen");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($sibling->getId()), 'an unchosen sibling is untouched by a reopen');
    }

    public function testReopeningAVersionThePlanStoppedPointingAtDoesNotClaimSuccess(): void
    {
        // Course : un autre onglet valide une AUTRE version pendant qu'on rouvre. Le
        // dépointage ne touche alors plus rien. Répondre 200 « rouvert » ferait croire
        // au gestionnaire qu'il peut éditer, et chaque geste serait refusé en 409.
        [$user, , $season] = $this->seed('REO9');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1);
        // L'issue de la course : le plan pointe désormais ailleurs.
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v2);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/reopen");

        self::assertResponseStatusCodeSame(409, 'on n\'annonce pas une réouverture qui n\'a pas eu lieu');
        self::assertSame($v2->getId(), $this->chosenPlanVersion($season), 'et le pointeur d\'autrui n\'est pas touché');
    }

    public function testAVersionThePlanDoesNotPointAtCannotBeReopened(): void
    {
        // "Validated" is the pointer and nothing else — a COMPLETED version the
        // plan does not point at has nothing to reopen.
        [$user, , $season] = $this->seed('REO2');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/reopen");

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignScheduleIsNotAccessible(): void
    {
        [$user] = $this->seed('REO3');
        [, , $otherSeason] = $this->seed('REO4');
        $foreign = $this->createSchedule($otherSeason, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($foreign);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$foreign->getId()}/reopen");

        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('R');
        $user->setLastName('EO');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);

        $this->em->flush();

        return [$user, $club, $season];
    }

    private function createSchedule(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    /**
     * A period entry with a generated overlay (schedule + one slot + dated FACILITY
     * constraint).
     *
     * @return array{0: CalendarEntry, 1: string, 2: string, 3: string} entry, overlayId, slotId, datedConstraintId
     */
    private function overlayEntry(Season $season, string $title): array
    {
        $overlay = new Schedule;
        $overlay->setClubId($season->getClubId());
        $overlay->setSeasonId($season->getId());
        $overlay->setName('Overlay ' . $title);
        $overlay->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($overlay);
        $this->em->flush();

        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle($title);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $entry->setOverlayScheduleId($overlay->getId());
        $this->em->persist($entry);
        $overlay->setCalendarEntryId($entry->getId());

        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($season->getClubId());
        $slot->setSeasonId($season->getId());
        $slot->setScheduleId($overlay->getId());
        $slot->setTeamId('44444444-4444-4444-8444-444444444444');
        $slot->setVenueId('55555555-5555-4555-8555-555555555555');
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);

        $dated = new Constraint;
        $dated->setClubId($season->getClubId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId('55555555-5555-4555-8555-555555555555');
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);
        $this->em->flush();

        return [$entry, $overlay->getId(), $slot->getId(), $dated->getId()];
    }
}
