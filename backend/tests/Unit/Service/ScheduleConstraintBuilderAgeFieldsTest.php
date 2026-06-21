<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ScheduleConstraintBuilderAgeFieldsTest extends TestCase
{
    private ScheduleConstraintBuilder $builder;

    private EntityManagerInterface&MockObject $entityManager;

    private EntityRepository&MockObject $sportCategoryRepository;

    private LoggerInterface&MockObject $logger;

    public function testBuildAddsAgeFieldsToEveryTeam(): void
    {
        $u13Category = (new SportCategory())
            ->setId('sport-category-u13m')
            ->setAgeMin(12)
            ->setAgeMax(13);

        $loisirCategory = (new SportCategory())
            ->setId('sport-category-loisir')
            ->setAgeMin(null)
            ->setAgeMax(null);

        $this->sportCategoryRepository->method('find')->willReturnCallback(
            static fn (string $id): ?SportCategory => match ($id) {
                'sport-category-u13m' => $u13Category,
                'sport-category-loisir' => $loisirCategory,
                default => null,
            },
        );

        $teams = [
            (new Team())
                ->setId('team-u13')
                ->setClubId('club-1')
                ->setSeasonId('season-1')
                ->setSportCategoryId('sport-category-u13m')
                ->setPriorityTierId(1)
                ->setName('U13M 1'),
            (new Team())
                ->setId('team-loisir')
                ->setClubId('club-1')
                ->setSeasonId('season-1')
                ->setSportCategoryId('sport-category-loisir')
                ->setPriorityTierId(1)
                ->setName('Loisir 1'),
        ];

        $payload = $this->builder->build([], $teams, []);

        self::assertCount(2, $payload['teams']);

        $teamsById = [];
        foreach ($payload['teams'] as $teamPayload) {
            self::assertArrayHasKey('ageMin', $teamPayload);
            self::assertArrayHasKey('ageMax', $teamPayload);
            $teamsById[$teamPayload['id']] = $teamPayload;
        }

        self::assertSame(12, $teamsById['team-u13']['ageMin']);
        self::assertSame(13, $teamsById['team-u13']['ageMax']);
        self::assertNull($teamsById['team-loisir']['ageMin']);
        self::assertNull($teamsById['team-loisir']['ageMax']);
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->sportCategoryRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager->method('getRepository')->willReturnMap([
            [SportCategory::class, $this->sportCategoryRepository],
        ]);

        $this->builder = new ScheduleConstraintBuilder($this->logger, $this->entityManager);
    }
}
