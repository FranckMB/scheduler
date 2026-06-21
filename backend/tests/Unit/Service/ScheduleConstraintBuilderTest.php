<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ScheduleConstraintBuilderTest extends TestCase
{
    private ScheduleConstraintBuilder $builder;

    private EntityManagerInterface&MockObject $entityManager;

    private EntityRepository&MockObject $teamTagRepository;

    private EntityRepository&MockObject $teamTagAssignmentRepository;

    private LoggerInterface&MockObject $logger;

    public function testResolveTagFiltersByClubId(): void
    {
        $seasonId = 'season-1';
        $club1Id = 'club-1';
        $tag = (new TeamTag())
            ->setId('tag-jeune-club-1')
            ->setClubId($club1Id)
            ->setName('JEUNE');

        $this->teamTagRepository->expects(self::once())
            ->method('findOneBy')
            ->with(self::callback(static function (array $criteria) use ($club1Id): bool {
                return 'JEUNE' === ($criteria['name'] ?? null)
                    && $club1Id === ($criteria['clubId'] ?? null);
            }))
            ->willReturn($tag);

        $this->teamTagAssignmentRepository->method('findBy')->willReturnCallback(
            static function (array $criteria): array {
                if ('tag-jeune-club-1' !== ($criteria['tagId'] ?? null)) {
                    return [];
                }

                $first = (new TeamTagAssignment())
                    ->setTeamId('team-a')
                    ->setTagId('tag-jeune-club-1')
                    ->setSeasonId('season-1');
                $second = (new TeamTagAssignment())
                    ->setTeamId('team-b')
                    ->setTagId('tag-jeune-club-1')
                    ->setSeasonId('season-1');

                return [$first, $second];
            }
        );

        $club1TeamIds = $this->invokeResolveTagToTeamIds('JEUNE', $seasonId, $club1Id);
        self::assertSame(['team-a', 'team-b'], $club1TeamIds);
    }

    public function testResolveTagLogsWarningWhenNotFound(): void
    {
        $seasonId = 'season-1';
        $clubId = 'club-1';

        $this->teamTagRepository->method('findOneBy')->willReturn(null);
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::callback(static fn (string $message): bool => str_contains($message, 'JEUNE') && str_contains($message, $clubId)),
                self::callback(static function (array $context) use ($clubId, $seasonId): bool {
                    return 'JEUNE' === ($context['targetTag'] ?? null)
                        && $clubId === ($context['clubId'] ?? null)
                        && $seasonId === ($context['seasonId'] ?? null);
                }),
            );

        $result = $this->invokeResolveTagToTeamIds('JEUNE', $seasonId, $clubId);

        self::assertSame([], $result);
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->teamTagRepository = $this->createMock(EntityRepository::class);
        $this->teamTagAssignmentRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager->method('getRepository')->willReturnMap([
            [TeamTag::class, $this->teamTagRepository],
            [TeamTagAssignment::class, $this->teamTagAssignmentRepository],
        ]);

        $this->builder = new ScheduleConstraintBuilder($this->logger, $this->entityManager);
    }

    private function invokeResolveTagToTeamIds(string $targetTag, string $seasonId, string $clubId): array
    {
        $method = new ReflectionMethod($this->builder, 'resolveTagToTeamIds');
        $method->setAccessible(true);

        $result = $method->invoke($this->builder, $targetTag, $seasonId, $clubId);

        return $result;
    }
}
