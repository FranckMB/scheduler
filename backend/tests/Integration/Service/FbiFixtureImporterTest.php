<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Service\FbiFixtureImporter;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FBI fixtures parser (spec gestion-matchs §5, ASSUMED format — validate against
 * a real export): HOME/AWAY from the club-name needle, Numéro = idempotence key,
 * Division = find-or-create Competition, per-row error report.
 */
#[Group('integration')]
final class FbiFixtureImporterTest extends KernelTestCase
{
    use TenantGucTrait;

    private const CLUB_NAME = 'BC Testville';

    private EntityManagerInterface $em;

    private FbiFixtureImporter $importer;

    private Club $club;

    private Team $team;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testImportsHomeAndAwayFixtures(): void
    {
        $file = $this->xlsx([
            ['D2 Poule A', 'R1001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'R1002', 'AS Voisins', 'BC TESTVILLE - 1', '10/10/2026', '', 'Salle Y'],
        ]);

        $result = $this->importer->import($file, $this->team, $this->club);

        self::assertSame(['created' => 2, 'skipped' => 0, 'errors' => []], $result);

        $fixtures = $this->em->getRepository(Fixture::class)->findBy(['teamId' => $this->team->getId()], ['matchDate' => 'ASC']);
        self::assertCount(2, $fixtures);

        // Home: club is Équipe 1 → opponent = Équipe 2, kickoff pre-filled, UNPLACED.
        self::assertSame(FixtureHomeAway::HOME, $fixtures[0]->getHomeAway());
        self::assertSame('AS Voisins', $fixtures[0]->getOpponentLabel());
        self::assertSame('2026-10-03', $fixtures[0]->getMatchDate()->format('Y-m-d'));
        self::assertSame('15:30', $fixtures[0]->getKickoffTime()?->format('H:i'));
        self::assertSame(FixtureStatus::UNPLACED, $fixtures[0]->getStatus());
        self::assertNull($fixtures[0]->getVenueId());
        self::assertSame('R1001', $fixtures[0]->getExternalRef());

        // Away: club is Équipe 2 → opponent = Équipe 1, empty Heure → null kickoff.
        self::assertSame(FixtureHomeAway::AWAY, $fixtures[1]->getHomeAway());
        self::assertSame('AS Voisins', $fixtures[1]->getOpponentLabel());
        self::assertNull($fixtures[1]->getKickoffTime());
    }

    public function testReimportIsIdempotent(): void
    {
        $rows = [['D2', 'R2001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']];

        $first = $this->importer->import($this->xlsx($rows), $this->team, $this->club);
        self::assertSame(1, $first['created']);

        $second = $this->importer->import($this->xlsx($rows), $this->team, $this->club);
        self::assertSame(['created' => 0, 'skipped' => 1, 'errors' => []], $second);
        self::assertCount(1, $this->em->getRepository(Fixture::class)->findBy(['teamId' => $this->team->getId()]));
    }

    public function testDivisionFindsOrCreatesOneCompetition(): void
    {
        $file = $this->xlsx([
            ['D2 Poule A', 'R3001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', ''],
            ['D2 Poule A', 'R3002', 'AS Autres', 'BC TESTVILLE - 1', '10/10/2026', '', ''],
        ]);

        $this->importer->import($file, $this->team, $this->club);

        $competitions = $this->em->getRepository(Competition::class)->findBy(['teamId' => $this->team->getId()]);
        self::assertCount(1, $competitions);
        self::assertSame('D2 Poule A', $competitions[0]->getName());

        $fixtures = $this->em->getRepository(Fixture::class)->findBy(['teamId' => $this->team->getId()]);
        foreach ($fixtures as $fixture) {
            self::assertSame($competitions[0]->getId(), $fixture->getCompetitionId());
        }
    }

    public function testUnrecognizedClubIsARowErrorAndValidRowsStillImport(): void
    {
        $file = $this->xlsx([
            ['D2', 'R4001', 'AS Ailleurs', 'US Autrepart', '03/10/2026', '', ''], // club absent
            ['D2', 'R4002', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->team, $this->club);

        self::assertSame(1, $result['created']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('aucune équipe ne correspond', $result['errors'][0]);
    }

    public function testIntraClubDerbyIsARowError(): void
    {
        $file = $this->xlsx([
            ['D2', 'R5001', 'BC TESTVILLE - 1', 'BC TESTVILLE - 2', '03/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->team, $this->club);

        self::assertSame(0, $result['created']);
        self::assertStringContainsString('derby intra-club', $result['errors'][0]);
    }

    public function testInvalidDateIsARowError(): void
    {
        $file = $this->xlsx([
            ['D2', 'R6001', 'BC TESTVILLE - 1', 'AS Voisins', 'pas-une-date', '', ''],
        ]);

        $result = $this->importer->import($file, $this->team, $this->club);

        self::assertSame(0, $result['created']);
        self::assertStringContainsString('date de rencontre invalide', $result['errors'][0]);
    }

    public function testMissingRequiredColumnsRejectTheFile(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Division', 'Numéro', 'Équipe 1']], null, 'A1');
        $file = $this->write($spreadsheet);

        $this->expectException(InvalidArgumentException::class);
        $this->importer->import($file, $this->team, $this->club);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(FbiFixtureImporter::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName(self::CLUB_NAME);
        $this->club->setSlug('bc-testville-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $season = new Season;
        $season->setClubId($this->club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        $this->team = new Team;
        $this->team->setClubId($this->club->getId());
        $this->team->setSeasonId($season->getId());
        $this->team->setSportCategoryId($this->createCategoryId());
        $this->team->setPriorityTierId(3);
        $this->team->setName('U13-1');
        $this->team->setSessionsPerWeek(2);
        $this->team->setIsActive(true);
        $this->em->persist($this->team);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function createCategoryId(): string
    {
        $sport = $this->em->getRepository(\App\Entity\Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new \App\Entity\Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new \App\Entity\SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);
        $this->em->flush();

        return $category->getId();
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'Numéro', 'Équipe 1', 'Équipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );

        return $this->write($spreadsheet);
    }

    private function write(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }
}
