<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\FfbbExcelImporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class FfbbExcelImporterTest extends TestCase
{
    private const CLUB_ID = '11111111-1111-1111-1111-111111111111';
    private const SEASON_ID = '22222222-2222-2222-2222-222222222222';
    private const SPORT_ID = '33333333-3333-3333-3333-333333333333';
    private const CLUB_CODE = '123456';

    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir().'/ffbb_test_'.uniqid().'.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /** @group phase1 */
    public function testImportCreatesTeamsWithCorrectData(): void
    {
        $this->createExcelFile([
            ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
            ['Team A', 'Séniors', '1001', self::CLUB_CODE.' - club - My Club'],
            ['Team B', 'U11', '1002', self::CLUB_CODE.' - club - My Club'],
        ]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(self::CLUB_CODE);
        $sport = (new Sport())->setId(self::SPORT_ID)->setName('Basket')->setSlug('basket');
        $category = (new SportCategory())->setId('cat-1')->setName('Séniors')->setSportId(self::SPORT_ID)->setClubId(self::CLUB_ID);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([
            Club::class => [$club],
            Sport::class => [$sport],
            SportCategory::class => [$category],
            Team::class => [],
        ]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );

        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->expects(self::exactly(2))->method('flush');

        $importer = new FfbbExcelImporter($entityManager);
        $result = $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);

        $teams = array_filter($persisted, static fn (object $e): bool => $e instanceof Team);
        self::assertCount(2, $teams);

        $teamA = $teams[0];
        self::assertSame('Team A', $teamA->getName());
        self::assertSame('cat-1', $teamA->getSportCategoryId());
        self::assertTrue($teamA->getIsCompetition());
        self::assertTrue($teamA->getIsActive());
        self::assertSame(5, $teamA->getPriorityTierId());
        self::assertSame(2, $teamA->getSessionsPerWeek());
    }

    /** @group phase1 */
    public function testImportSkipsExistingTeams(): void
    {
        $this->createExcelFile([
            ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
            ['Team A', 'Séniors', '1001', self::CLUB_CODE.' - club - My Club'],
        ]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(self::CLUB_CODE);
        $existingTeam = (new Team())
            ->setId('team-existing')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setSportCategoryId('cat-1')
            ->setPriorityTierId(5)
            ->setName('Existing')
            ->setSessionsPerWeek(2);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([
            Club::class => [$club],
            Sport::class => [(new Sport())->setId(self::SPORT_ID)->setName('Basket')->setSlug('basket')],
            SportCategory::class => [(new SportCategory())->setId('cat-1')->setName('Séniors')->setSportId(self::SPORT_ID)->setClubId(self::CLUB_ID)],
            Team::class => [$existingTeam],
        ]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $importer = new FfbbExcelImporter($entityManager);
        $result = $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['skipped']);
    }

    /** @group phase1 */
    public function testImportRejectsMismatchedClubCode(): void
    {
        $this->createExcelFile([
            ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
            ['Team A', 'Séniors', '1001', '999999 - club - Other Club'],
        ]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(self::CLUB_CODE);
        $sport = (new Sport())->setId(self::SPORT_ID)->setName('Basket')->setSlug('basket');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([
            Club::class => [$club],
            Sport::class => [$sport],
        ]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );

        $importer = new FfbbExcelImporter($entityManager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Identity theft prevention');

        $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);
    }

    /** @group phase1 */
    public function testImportCreatesSportCategoryIfNotExists(): void
    {
        $this->createExcelFile([
            ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
            ['Team A', 'U18', '1001', self::CLUB_CODE.' - club - My Club'],
        ]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(self::CLUB_CODE);
        $sport = (new Sport())->setId(self::SPORT_ID)->setName('Basket')->setSlug('basket');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([
            Club::class => [$club],
            Sport::class => [$sport],
            SportCategory::class => [],
            Team::class => [],
        ]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );

        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->expects(self::exactly(2))->method('flush');

        $importer = new FfbbExcelImporter($entityManager);
        $result = $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);

        self::assertSame(1, $result['created']);

        $categories = array_filter($persisted, static fn (object $e): bool => $e instanceof SportCategory);
        self::assertCount(1, $categories);
        self::assertSame('U18', $categories[0]->getName());
        self::assertSame(self::CLUB_ID, $categories[0]->getClubId());
        self::assertSame(self::SPORT_ID, $categories[0]->getSportId());
        self::assertTrue($categories[0]->getIsCustom());
    }

    /** @group phase1 */
    public function testImportRequiresAllColumns(): void
    {
        $this->createExcelFile([
            ['Nom', 'Numéro'],
            ['Team A', '1001'],
        ]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(self::CLUB_CODE);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([Club::class => [$club]]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );

        $importer = new FfbbExcelImporter($entityManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required columns missing');

        $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);
    }

    /** @group phase1 */
    public function testImportRequiresClubToHaveFfbbCode(): void
    {
        $this->createExcelFile([
            ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
            ['Team A', 'Séniors', '1001', self::CLUB_CODE.' - club - My Club'],
        ]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([Club::class => [$club]]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );

        $importer = new FfbbExcelImporter($entityManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Club does not have an FFBB club code configured.');

        $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);
    }

    /** @group phase1 */
    public function testImportReturnsEmptyResultForEmptyFile(): void
    {
        $this->createExcelFile([]);

        $club = (new Club())->setId(self::CLUB_ID)->setFfbbClubCode(self::CLUB_CODE);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repositories = $this->buildRepositories([Club::class => [$club]]);

        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );

        $importer = new FfbbExcelImporter($entityManager);
        $result = $importer->import($this->tempFile, self::CLUB_ID, self::SEASON_ID);

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertSame(['Excel file is empty.'], $result['errors']);
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function createExcelFile(array $rows): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($this->tempFile);
    }

    /**
     * @param array<class-string, list<object>> $data
     *
     * @return array<class-string, EntityRepository>
     */
    private function buildRepositories(array $data): array
    {
        $repositories = [];
        foreach ($data as $className => $items) {
            $repository = $this->createMock(EntityRepository::class);
            $repository->method('find')->willReturnCallback(
                static function (string $id) use ($items): ?object {
                    foreach ($items as $item) {
                        if (method_exists($item, 'getId') && $item->getId() === $id) {
                            return $item;
                        }
                    }

                    return null;
                },
            );
            $repository->method('findOneBy')->willReturnCallback(
                static function (array $criteria) use ($items): ?object {
                    foreach ($items as $item) {
                        $match = true;
                        foreach ($criteria as $field => $expected) {
                            $getter = 'get'.ucfirst($field);
                            if (!method_exists($item, $getter) || $item->$getter() !== $expected) {
                                $match = false;
                                break;
                            }
                        }
                        if ($match) {
                            return $item;
                        }
                    }

                    return null;
                },
            );
            $repositories[$className] = $repository;
        }

        return $repositories;
    }
}
