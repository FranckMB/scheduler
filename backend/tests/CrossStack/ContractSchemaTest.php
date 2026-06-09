<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamConstraint;
use App\Entity\Venue;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

final class ContractSchemaTest extends KernelTestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';
    }

    private static function contractVersionFile(): string
    {
        return dirname(__DIR__, 3).'/engine/CONTRACT_VERSION';
    }
    private const ENGINE_VALIDATE_URL = 'http://clubscheduler-engine:8000/validate';

    /** @group phase1 */
    public function testContractVersionFileExists(): void
    {
        self::assertFileExists(self::contractVersionFile(), 'engine/CONTRACT_VERSION must exist');
    }

    /** @group phase1 */
    public function testContractVersionIsNotEmpty(): void
    {
        $content = file_get_contents(self::contractVersionFile());
        self::assertNotEmpty($content, 'CONTRACT_VERSION must not be empty');
    }

    /** @group phase2 */
    public function testStubJsonValidatesAgainstPydantic(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->executeStatement("SET LOCAL app.club_id = '11111111-1111-1111-1111-111111111111'");

        // Create club and season
        $club = new Club();
        $club->setName('Test Club');
        $club->setSlug('test-club-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $em->persist($club);

        $season = new Season();
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $em->persist($season);

        // Create sport category
        $sportCategory = new SportCategory();
        $sportCategory->setSportId('33333333-3333-3333-3333-333333333333');
        $sportCategory->setName('U13');
        $sportCategory->setIsCustom(false);
        $sportCategory->setSortOrder(1);
        $em->persist($sportCategory);

        // Create 4 venues
        $venues = [];
        for ($i = 1; $i <= 4; ++$i) {
            $venue = new Venue();
            $venue->setClubId($club->getId());
            $venue->setSeasonId($season->getId());
            $venue->setName('Venue '.$i);
            $venue->setSource('manual');
            $venue->setIsActive(true);
            $em->persist($venue);
            $venues[] = $venue;
        }

        // Create 10 coaches
        $coaches = [];
        for ($i = 1; $i <= 10; ++$i) {
            $coach = new Coach();
            $coach->setClubId($club->getId());
            $coach->setSeasonId($season->getId());
            $coach->setFirstName('Coach');
            $coach->setLastName((string) $i);
            $coach->setIsActive(true);
            $em->persist($coach);
            $coaches[] = $coach;
        }

        // Create 20 teams
        $teams = [];
        for ($i = 1; $i <= 20; ++$i) {
            $team = new Team();
            $team->setClubId($club->getId());
            $team->setSeasonId($season->getId());
            $team->setSportCategoryId($sportCategory->getId());
            $team->setPriorityTierId(1);
            $team->setName('Team '.$i);
            $team->setSessionsPerWeek(2);
            $team->setIsActive(true);
            $em->persist($team);
            $teams[] = $team;
        }

        // Create constraints
        $constraints = [];
        for ($i = 0; $i < 5; ++$i) {
            $constraint = new TeamConstraint();
            $constraint->setClubId($club->getId());
            $constraint->setSeasonId($season->getId());
            $constraint->setTeamId($teams[$i]->getId());
            $constraint->setType('preferred');
            $em->persist($constraint);
            $constraints[] = $constraint;
        }

        $em->flush();

        // Build JSON
        $builder = new ScheduleConstraintBuilder();
        $payload = $builder->build($venues, $teams, $coaches, $constraints);

        // Validate against Pydantic engine
        $client = HttpClient::create();
        $response = $client->request('POST', self::ENGINE_VALIDATE_URL, [
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        self::assertSame(200, $response->getStatusCode(), 'Engine validation should return 200');
        $data = $response->toArray();
        self::assertTrue($data['valid'] ?? false, 'Engine should report JSON as valid');
    }
}
