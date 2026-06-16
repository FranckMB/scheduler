<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Service\TeamTagService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class TeamTagServiceTest extends TestCase
{
    private TeamTagService $service;

    private EntityManagerInterface $entityManager;

    /** @var EntityRepository<TeamTagAssignment>&\PHPUnit\Framework\MockObject\MockObject */
    private EntityRepository $assignmentRepository;

    /** @var EntityRepository<TeamTag>&\PHPUnit\Framework\MockObject\MockObject */
    private EntityRepository $teamTagRepository;

    /** @var EntityRepository<SportCategory>&\PHPUnit\Framework\MockObject\MockObject */
    private EntityRepository $sportCategoryRepository;

    public function testSyncTeamTagsForU15F(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-u15');
        $team->setGender(Gender::F);
        $team->setLevel(TeamLevel::REGIONAL);

        $sportCategory = new SportCategory;
        $sportCategory->setName('U15F');
        $sportCategory->setAgeMin(13);
        $sportCategory->setAgeMax(15);

        $this->assignmentRepository->method('findBy')
            ->with(['teamId' => $team->getId(), 'seasonId' => 'season-1'])
            ->willReturn([]);

        $this->teamTagRepository->method('findBy')
            ->with(['clubId' => 'club-1', 'isSystem' => true])
            ->willReturn([]);

        $this->sportCategoryRepository->method('find')
            ->with('cat-u15')
            ->willReturn($sportCategory);

        $persistedAssignments = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedAssignments): void {
                if ($entity instanceof TeamTagAssignment) {
                    $persistedAssignments[] = $entity;
                }
            });

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        $tagNames = array_map(static fn (TeamTagAssignment $a): string => $a->getTagId(), $persistedAssignments);

        // U15F should generate: JEUNE, U15, FEMININE, REGIONAL
        self::assertCount(4, $persistedAssignments);
    }

    public function testSyncTeamTagsForSeniorLoisir(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-senior');
        $team->setGender(Gender::M);
        $team->setLevel(TeamLevel::LOISIR);

        $sportCategory = new SportCategory;
        $sportCategory->setName('Senior');
        $sportCategory->setAgeMin(19);
        $sportCategory->setAgeMax(99);

        $this->assignmentRepository->method('findBy')
            ->with(['teamId' => $team->getId(), 'seasonId' => 'season-1'])
            ->willReturn([]);

        $this->teamTagRepository->method('findBy')
            ->with(['clubId' => 'club-1', 'isSystem' => true])
            ->willReturn([]);

        $this->sportCategoryRepository->method('find')
            ->with('cat-senior')
            ->willReturn($sportCategory);

        $persistedAssignments = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedAssignments): void {
                if ($entity instanceof TeamTagAssignment) {
                    $persistedAssignments[] = $entity;
                }
            });

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        // Senior Loisir should generate: SENIOR, MASCULINE, LOISIR
        self::assertCount(3, $persistedAssignments);
    }

    public function testSyncTeamTagsRemovesExistingAssignments(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-1');
        $team->setGender(Gender::MIXTE);

        $existingAssignment = new TeamTagAssignment;
        $existingAssignment->setTeamId($team->getId());
        $existingAssignment->setSeasonId('season-1');

        $this->assignmentRepository->method('findBy')
            ->willReturn([$existingAssignment]);

        $this->teamTagRepository->method('findBy')
            ->willReturn([]);

        $this->sportCategoryRepository->method('find')
            ->willReturn(null);

        $removedEntities = [];
        $this->entityManager->method('remove')
            ->willReturnCallback(function ($entity) use (&$removedEntities): void {
                $removedEntities[] = $entity;
            });

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        self::assertCount(1, $removedEntities);
        self::assertSame($existingAssignment, $removedEntities[0]);
    }

    public function testSyncTeamTagsCreatesMissingSystemTags(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-1');
        $team->setGender(Gender::M);

        $this->assignmentRepository->method('findBy')
            ->willReturn([]);

        $this->teamTagRepository->method('findBy')
            ->willReturn([]);

        $this->sportCategoryRepository->method('find')
            ->willReturn(null);

        $persistedTags = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedTags): void {
                if ($entity instanceof TeamTag) {
                    $persistedTags[] = $entity;
                }
            });

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        // All 20 required system tags should be created
        self::assertCount(20, $persistedTags);

        $tagNames = array_map(static fn (TeamTag $t): string => $t->getName(), $persistedTags);
        self::assertContains('JEUNE', $tagNames);
        self::assertContains('SENIOR', $tagNames);
        self::assertContains('MASCULINE', $tagNames);
        self::assertContains('LOISIR', $tagNames);
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->assignmentRepository = $this->createMock(EntityRepository::class);
        $this->teamTagRepository = $this->createMock(EntityRepository::class);
        $this->sportCategoryRepository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [TeamTagAssignment::class, $this->assignmentRepository],
                [TeamTag::class, $this->teamTagRepository],
                [SportCategory::class, $this->sportCategoryRepository],
            ]);

        $this->service = new TeamTagService($this->entityManager);
    }
}
