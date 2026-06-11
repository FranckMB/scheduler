<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

final class WizardControllerTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private ?EntityManagerInterface $entityManager = null;

    /** @var list<string> */
    private array $createdClubIds = [];

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        parent::setUp();

        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        // Schema is recreated in setUp, so no entity cleanup needed.
        // The ApiTestCase client uses a separate EM, making direct removal unreliable.
        if (null !== $this->entityManager) {
            $this->entityManager->close();
            $this->entityManager = null;
        }

        parent::tearDown();
    }

    private function createSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /** @group phase1 */
    public function testCreateVenueWithValidPayloadReturns201(): void
    {
        $club = $this->createClub('Wizard Venue Club');
        $season = $this->createSeason($club);

        $response = self::createClient()->request('POST', '/api/venues', [
            'headers' => [
                'X-Club-Id' => $club->getId(),
                'X-Season-Id' => $season->getId(),
            ],
            'json' => [
                'name' => 'Main Gymnasium',
                'source' => 'manual',
                'isActive' => true,
                'isExternal' => false,
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), 'Venue creation with valid payload should return 201');

        $data = $response->toArray();
        self::assertArrayHasKey('id', $data, 'Response should contain venue id');
        self::assertSame('Main Gymnasium', $data['name']);
        self::assertSame('manual', $data['source']);
    }

    /** @group phase1 */
    public function testCreateVenueWithoutSourceReturns400(): void
    {
        $club = $this->createClub('No Source Club');
        $season = $this->createSeason($club);

        $response = self::createClient()->request('POST', '/api/venues', [
            'headers' => [
                'X-Club-Id' => $club->getId(),
                'X-Season-Id' => $season->getId(),
            ],
            'json' => [
                'name' => 'Gym Without Source',
                'isActive' => true,
                'isExternal' => false,
            ],
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [400, 422],
            'Venue creation without source should return 400 or 422'
        );
    }

    /** @group phase1 */
    public function testCreateScheduleWithValidPayloadReturns201(): void
    {
        $club = $this->createClub('Wizard Schedule Club');
        $season = $this->createSeason($club);

        $response = self::createClient()->request('POST', '/api/schedules', [
            'headers' => [
                'X-Club-Id' => $club->getId(),
                'X-Season-Id' => $season->getId(),
            ],
            'json' => [
                'name' => 'Spring 2026 Schedule',
                'status' => 'draft',
                'solverSeed' => 42,
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), 'Schedule creation with valid payload should return 201');

        $data = $response->toArray();
        self::assertArrayHasKey('id', $data, 'Response should contain schedule id');
        self::assertSame('Spring 2026 Schedule', $data['name']);
        self::assertSame('draft', $data['status']);
    }

    /** @group phase1 */
    public function testCreateScheduleWithoutStatusReturns400(): void
    {
        $club = $this->createClub('No Status Club');
        $season = $this->createSeason($club);

        $response = self::createClient()->request('POST', '/api/schedules', [
            'headers' => [
                'X-Club-Id' => $club->getId(),
                'X-Season-Id' => $season->getId(),
            ],
            'json' => [
                'name' => 'Schedule Without Status',
                'solverSeed' => 42,
            ],
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [400, 422],
            'Schedule creation without status should return 400 or 422'
        );
    }

    /** @group phase1 */
    public function testGetVenuesWithTenantFilterReturnsOnlyClubVenues(): void
    {
        $clubA = $this->createClub('Club Alpha');
        $seasonA = $this->createSeason($clubA);
        $clubB = $this->createClub('Club Beta');
        $seasonB = $this->createSeason($clubB);

        // Create venue for Club A
        $venueA = new Venue();
        $venueA->setClubId($clubA->getId());
        $venueA->setSeasonId($seasonA->getId());
        $venueA->setName('Alpha Gym');
        $venueA->setSource('manual');
        $this->entityManager->persist($venueA);

        // Create venue for Club B
        $venueB = new Venue();
        $venueB->setClubId($clubB->getId());
        $venueB->setSeasonId($seasonB->getId());
        $venueB->setName('Beta Gym');
        $venueB->setSource('manual');
        $this->entityManager->persist($venueB);

        $this->entityManager->flush();

        // Request venues for Club A
        $responseA = self::createClient()->request('GET', '/api/venues', [
            'headers' => [
                'X-Club-Id' => $clubA->getId(),
                'X-Season-Id' => $seasonA->getId(),
            ],
        ]);

        self::assertSame(200, $responseA->getStatusCode(), 'GET /api/venues should return 200');

        $dataA = $responseA->toArray();
        $membersA = $dataA['member'];
        $venueNamesA = array_map(static fn (array $v): string => $v['name'], $membersA);
        self::assertContains('Alpha Gym', $venueNamesA, 'Club A should see its own venue');
        self::assertNotContains('Beta Gym', $venueNamesA, 'Club A should not see Club B venues');

        // Request venues for Club B
        $responseB = self::createClient()->request('GET', '/api/venues', [
            'headers' => [
                'X-Club-Id' => $clubB->getId(),
                'X-Season-Id' => $seasonB->getId(),
            ],
        ]);

        self::assertSame(200, $responseB->getStatusCode(), 'GET /api/venues should return 200');

        $dataB = $responseB->toArray();
        $membersB = $dataB['member'];
        $venueNamesB = array_map(static fn (array $v): string => $v['name'], $membersB);
        self::assertContains('Beta Gym', $venueNamesB, 'Club B should see its own venue');
        self::assertNotContains('Alpha Gym', $venueNamesB, 'Club B should not see Club A venues');
    }

    private function createClub(string $name): Club
    {
        $club = new Club();
        $club->setName($name);
        $club->setSlug(strtolower(str_replace(' ', '-', $name)).'-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->entityManager->persist($club);
        $this->entityManager->flush();
        $this->createdClubIds[] = $club->getId();

        return $club;
    }

    private function createSeason(Club $club): Season
    {
        $season = new Season();
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);

        $this->entityManager->persist($season);
        $this->entityManager->flush();

        return $season;
    }
}
