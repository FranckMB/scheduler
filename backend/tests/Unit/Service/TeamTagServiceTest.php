<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Enum\TeamTagAxis;
use App\Service\TeamTagService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TeamTagServiceTest extends TestCase
{
    private TeamTagService $service;

    /** @var EntityManagerInterface&MockObject */
    private MockObject $entityManager;

    /** @var EntityRepository<TeamTagAssignment>&MockObject */
    private MockObject $assignmentRepository;

    /** @var EntityRepository<TeamTag>&MockObject */
    private MockObject $teamTagRepository;

    /** @var EntityRepository<SportCategory>&MockObject */
    private MockObject $sportCategoryRepository;

    /**
     * La tranche d'âge posée par catégorie, aux âges RÉELS du catalogue
     * (`Service\Basketball\CategoryCatalog`) — inventer un jeu d'âges testerait une
     * frontière qui n'existe nulle part.
     *
     * Complément rapide au NR d'intégration `TeamTagScopeTest` : ici on lit le NOM du tag
     * posé, catégorie par catégorie, sans base.
     *
     * @return iterable<string, array{0: string, 1: int|null, 2: int|null, 3: string|null}>
     */
    public static function ageBracketCases(): iterable
    {
        yield 'U5 → BABY' => ['U5', 3, 5, 'BABY'];
        yield 'U7 → BABY (dernière année de la tranche)' => ['U7', 6, 7, 'BABY'];
        yield 'U9 → EMB (première année d\'EMB)' => ['U9', 8, 9, 'EMB'];
        yield 'U11 → EMB' => ['U11', 10, 11, 'EMB'];
        yield 'U13 → JEUNE, jamais EMB' => ['U13', 12, 13, 'JEUNE'];
        // Sans âge : seul le NOM peut trancher. « Baby basket » entre, « Loisir » non —
        // c'est la paire qui prouve que la règle vise « baby » et n'avale pas toute
        // catégorie sans âge.
        yield 'Baby basket → BABY par son nom, faute d\'âges' => ['Baby basket', null, null, 'BABY'];
        yield 'Loisir → aucune tranche' => ['Loisir', null, null, null];
    }

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
        $team->setLevel(TeamLevel::LOISIR_ADULTE);

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

        $tagNames = array_map(static fn (TeamTag $t): string => $t->getName(), $persistedTags);

        // Le catalogue de tags système est semé EN ENTIER sur un club qui n'en a aucun,
        // et sans doublon. On l'assertait par un compte en dur (21) : ajouter BABY le
        // faisait rougir pour la seule raison qu'il avait bougé — un nombre magique dit
        // « ça a changé », jamais « c'est faux ». Ce qu'on veut garder, c'est qu'aucun tag
        // ne soit semé deux fois, et que les tranches d'âge y soient toutes.
        self::assertSame(array_unique($tagNames), $tagNames, 'aucun tag système semé en double');
        foreach (['BABY', 'EMB', 'JEUNE', 'SENIOR'] as $bracket) {
            self::assertContains($bracket, $tagNames, 'toutes les tranches d\'âge sont semées');
        }
        self::assertContains('JEUNE', $tagNames);
        self::assertContains('SENIOR', $tagNames);
        self::assertContains('MASCULINE', $tagNames);
        self::assertContains('LOISIR_ADULTE', $tagNames);
        self::assertContains('LOISIR_JEUNE', $tagNames);

        // Lot B: each system tag is created with its deterministic axis.
        $byName = [];
        foreach ($persistedTags as $tag) {
            $byName[$tag->getName()] = $tag;
        }
        self::assertSame(TeamTagAxis::GENRE, $byName['MASCULINE']->getAxis());
        self::assertSame(TeamTagAxis::AGE, $byName['U15']->getAxis());
        self::assertSame(TeamTagAxis::AGE, $byName['SENIOR']->getAxis());
        self::assertSame(TeamTagAxis::NIVEAU, $byName['DEPARTEMENTAL']->getAxis());
    }

    #[DataProvider('ageBracketCases')]
    public function testAgeBracketTagPerCategory(string $categoryName, ?int $ageMin, ?int $ageMax, ?string $expected): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-1');

        $category = new SportCategory;
        $category->setName($categoryName);
        $category->setAgeMin($ageMin);
        $category->setAgeMax($ageMax);

        $this->assignmentRepository->method('findBy')->willReturn([]);
        // Les tags système préexistent, chacun avec son NOM pour id : `syncTeamTags` écrit
        // `$systemTags[$name]->getId()` dans l'assignation, donc lire le tagId revient à
        // lire le nom du tag posé — ce que le mock ne permettait pas jusqu'ici.
        $this->teamTagRepository->method('findBy')->willReturn($this->systemTagsKeyedByName());

        $this->sportCategoryRepository->method('find')->with('cat-1')->willReturn($category);

        $assigned = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$assigned): void {
                if ($entity instanceof TeamTagAssignment) {
                    $assigned[] = $entity->getTagId();
                }
            });

        $this->service->syncTeamTags($team, 'season-1');

        $brackets = array_values(array_intersect($assigned, ['BABY', 'EMB', 'JEUNE', 'SENIOR']));
        self::assertSame(null === $expected ? [] : [$expected], $brackets, \sprintf('tranche d\'âge de « %s »', $categoryName));
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

    /**
     * Tous les tags système, id = nom, pour que l'assignation soit lisible.
     *
     * @return list<TeamTag>
     */
    private function systemTagsKeyedByName(): array
    {
        $tags = [];
        foreach (['BABY', 'EMB', 'JEUNE', 'SENIOR', 'U9', 'U11', 'U13', 'U15', 'U18', 'U21', 'FEMININE', 'MASCULINE', 'MIXTE'] as $name) {
            $tag = new TeamTag;
            $tag->setId($name);
            $tag->setName($name);
            $tag->setClubId('club-1');
            $tag->setIsSystem(true);
            $tag->setAxis(TeamTagAxis::AGE);
            $tags[] = $tag;
        }

        return $tags;
    }
}
