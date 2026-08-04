<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\TenantGucTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P4-67 — NR de l'unicité qui rend la résolution d'import déterministe :
 * deux « DF2 » pour la MÊME équipe sont refusés par la BASE (le résolveur
 * d'import prenait candidates[0], arbitraire). Deux équipes en DF2 restent
 * légitimes — y compris appariées à la MÊME compétition FFBB (deux U11 dans
 * une même division : le cas multiLabel de l'importeur).
 */
#[Group('integration')]
final class CompetitionUniquenessTest extends KernelTestCase
{
    use TenantGucTrait;

    private const CLUB = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa67';
    private const SEASON = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb67';
    private const TEAM_A = 'cccccccc-cccc-4ccc-8ccc-cccccccccc67';
    private const TEAM_B = 'dddddddd-dddd-4ddd-8ddd-dddddddddd67';

    private Connection $connection;

    public function testSameTeamSameNameIsRejectedByTheDatabase(): void
    {
        $this->insertCompetition(self::TEAM_A, 'DF2');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertCompetition(self::TEAM_A, 'DF2');
    }

    public function testTwoTeamsMaySharePetitionNameAndFfbbRef(): void
    {
        // Le contre-exemple qui a failli être interdit : même nom ET même réf
        // FFBB sur deux équipes — légitime (deux équipes du club dans la même
        // division). Aucune contrainte ne doit s'y opposer.
        $this->insertCompetition(self::TEAM_A, 'DFU11-2', '900000000000042');
        $this->insertCompetition(self::TEAM_B, 'DFU11-2', '900000000000042');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM competition WHERE club_id = ?', [self::CLUB]));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->scopeGucToClub(self::CLUB);
    }

    private function insertCompetition(string $teamId, string $name, ?string $ffbbId = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO competition (id, version, created_at, updated_at, club_id, season_id, team_id, name, competition_type, ffbb_competition_id)
             VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, ?, ?, ?, ?)',
            [self::CLUB, self::SEASON, $teamId, $name, 'CHAMPIONSHIP', $ffbbId],
        );
    }
}
