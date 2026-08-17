<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + sémantique de contrainte (§7.1).
 *
 * P2-27 mutualisation : ce que le club STOCKE (groupes {équipes, K}, ancrés au plan) doit être
 * EXACTEMENT le bloc `sharedTrainings` que le payload émet au solveur. La portée est dérivée du
 * plan (ADR-0002) : le socle (schedulePlanId NULL) émet ses groupes dans le build base ; une
 * période émet les SIENS (= planId) — et jamais les uns dans le build de l'autre.
 *
 * Falsifié dans les DEUX sens : un groupe stocké DOIT apparaître (un builder qui émettrait []
 * échoue) ET un groupe d'une AUTRE portée NE doit PAS fuir (un builder aveugle au plan échoue).
 */
#[Group('phase1')]
#[Group('integration')]
final class SharedTrainingPayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    /**
     * Chemin base : le socle émet ses groupes ; un groupe de PÉRIODE ne fuit pas dans la base.
     */
    public function testClubSeasonPayloadEmitsBaseGroupsOnly(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $t3 = $this->team($club, $season, 2);
        $this->em->flush();

        // Un groupe SOCLE (plan NULL) et un groupe de PÉRIODE (planId) — seul le socle doit sortir.
        $baseGroup = $this->group($club, $season, null, [$t1, $t2], 1);
        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);
        $this->group($club, $season, $planId, [$t1, $t3], 2);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        // Sens 1 — le groupe socle stocké est REFLÉTÉ exactement (un builder émettant [] échoue).
        self::assertSame(
            [[
                'id' => $baseGroup->getId(),
                'teamIds' => $this->sorted([$t1->getId(), $t2->getId()]),
                'commonSessions' => 1,
            ]],
            $payload['sharedTrainings'],
            'la base émet EXACTEMENT ses groupes socle (plan NULL)',
        );

        // Sens 2 — aucun teamId du groupe de période (t3) ne fuit dans la base.
        $emittedTeamIds = array_merge(...array_column($payload['sharedTrainings'], 'teamIds'));
        self::assertNotContains($t3->getId(), $emittedTeamIds, 'un groupe de période ne doit pas fuir dans la base');
    }

    /**
     * Chemin overlay de période : la période émet SES groupes (= planId) ; le socle ne fuit pas.
     */
    public function testPeriodOverlayPayloadEmitsPeriodGroupsOnly(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 3);
        $t2 = $this->team($club, $season, 3);
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);

        // Un groupe socle (ne doit PAS sortir en période) et un groupe de période (= planId).
        $this->group($club, $season, null, [$t1, $t2], 1);
        $periodGroup = $this->group($club, $season, $planId, [$t1, $t2], 2);
        $this->em->flush();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertSame(
            [[
                'id' => $periodGroup->getId(),
                'teamIds' => $this->sorted([$t1->getId(), $t2->getId()]),
                'commonSessions' => 2,
            ]],
            $payload['sharedTrainings'],
            'la période émet EXACTEMENT ses propres groupes (= planId), jamais le socle',
        );
    }

    /**
     * Aucun groupe stocké ⇒ bloc VIDE : chemin byte-identique côté moteur (default_factory=list).
     */
    public function testNoGroupsEmitsEmptyBlock(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 2);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame([], $payload['sharedTrainings']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function team(Club $club, Season $season, int $sessionsPerWeek): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->uuid());
        $team->setPriorityTierId(3);
        $team->setName('T' . substr($this->uuid(), 0, 6));
        $team->setSessionsPerWeek($sessionsPerWeek);
        $team->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    /**
     * @param list<Team> $teams
     */
    private function group(Club $club, Season $season, ?string $planId, array $teams, int $commonSessions): SharedTrainingGroup
    {
        $group = new SharedTrainingGroup;
        $group->setClubId($club->getId());
        $group->setSeasonId($season->getId());
        $group->setSchedulePlanId($planId);
        $group->setCommonSessions($commonSessions);
        $this->em->persist($group);

        foreach ($teams as $team) {
            $member = new SharedTrainingGroupTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setSchedulePlanId($planId);
            $member->setGroupId($group->getId());
            $member->setTeamId($team->getId());
            $this->em->persist($member);
        }

        return $group;
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Shared Parity Club');
        $club->setSlug('shared-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SPC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('shared-parity-' . $uid . '@test.com');
        $user->setFirstName('S');
        $user->setLastName('P');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
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
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
