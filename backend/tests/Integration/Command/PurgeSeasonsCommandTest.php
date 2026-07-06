<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\PurgeSeasonsCommand;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retention purge NR (transition-de-saison §3): keep current + N-1 + futures,
 * delete N-2 and older (Season row included). Dry-run touches nothing;
 * one bad club never blocks the others.
 */
#[Group('phase1')]
#[Group('integration')]
final class PurgeSeasonsCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testDryRunDeletesNothing(): void
    {
        [$club, $seasons] = $this->createClubWithSeasons();
        [$old] = $seasons;

        $tester = $this->runPurge(['--dry-run' => true, '--club' => $club->getId()]);
        $tester->assertCommandIsSuccessful();

        // The command cleared the GUC in its finally block — re-scope so the
        // RLS-protected season table is readable again for the assertions.
        $this->scopeGucToClub($club->getId());

        // The N-2 season and its team are still there.
        self::assertNotNull($this->em->getRepository(Season::class)->find($old->getId()));
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['seasonId' => $old->getId()]));
        self::assertStringContainsString('would purge', $tester->getDisplay());
    }

    public function testPurgesOnlySeasonsOlderThanPredecessor(): void
    {
        [$club, $seasons] = $this->createClubWithSeasons();
        [$old, $past, $current, $draft] = $seasons;

        $tester = $this->runPurge(['--club' => $club->getId()]);
        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());

        // N-2 (old) purged, row and children gone (team + its tag assignment).
        self::assertNull($this->em->getRepository(Season::class)->find($old->getId()));
        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['seasonId' => $old->getId()]));
        self::assertCount(0, $this->em->getRepository(TeamTagAssignment::class)->findBy(['seasonId' => $old->getId()]));
        // current, N-1 and the future draft survive.
        self::assertNotNull($this->em->getRepository(Season::class)->find($past->getId()));
        self::assertNotNull($this->em->getRepository(Season::class)->find($current->getId()));
        self::assertNotNull($this->em->getRepository(Season::class)->find($draft->getId()));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runPurge(array $options): CommandTester
    {
        $command = self::getContainer()->get(PurgeSeasonsCommand::class);
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    /**
     * Seasons: N-2 (old, with a team), N-1, current, N+1 draft.
     *
     * @return array{0: Club, 1: array{0: Season, 1: Season, 2: Season, 3: Season}}
     */
    private function createClubWithSeasons(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club purge');
        $club->setSlug('club-purge-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PUR' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $old = $this->season($club, $year - 2);
        $past = $this->season($club, $year - 1);
        $current = $this->season($club, $year);
        $draft = $this->season($club, $year + 1);
        $this->em->flush();

        // A team in the N-2 season, to prove children are purged too.
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($old->getId());
        $team->setSportCategoryId('00000000-0000-4000-8000-0000000000c1');
        $team->setPriorityTierId(1);
        $team->setName('Vieille équipe');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        // A tag assignment in the N-2 season — the outlier table (season_id,
        // no club_id) SeasonDataPurger must delete by season.
        $tag = new TeamTag;
        $tag->setClubId($club->getId());
        $tag->setName('CUSTOM-' . substr($uid, -4));
        $tag->setIsSystem(false);
        $this->em->persist($tag);
        $this->em->flush();

        $assignment = new TeamTagAssignment;
        $assignment->setSeasonId($old->getId());
        $assignment->setTeamId($team->getId());
        $assignment->setTagId($tag->getId());
        $this->em->persist($assignment);
        $this->em->flush();

        return [$club, [$old, $past, $current, $draft]];
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }
}
