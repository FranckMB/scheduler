<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Onboarding happy path (minimal), driven through the real API with a real JWT
 * (not loginUser). A brand-new club is empty (isolation), the manager enters the
 * minimum (team + gym slot + coach), creates a schedule and launches generation:
 * the club becomes onboarded and the schedule is queued. The COMPLETED plan
 * itself (engine solve) is covered by scripts/onboarding-smoke.sh.
 */
#[Group('phase1')]
#[Group('integration')]
final class OnboardingFlowTest extends WebTestCase
{
    private static int $ip = 0;

    private KernelBrowser $client;

    private string $token = '';

    public function testMinimalOnboardingCompletesAndQueuesGeneration(): void
    {
        // 1. Create the account (new club → active admin, real JWT).
        $this->token = $this->register('ONB' . uniqid());
        self::assertNotSame('', $this->token, 'register returns a JWT');

        // 2. A fresh club is EMPTY (tenant isolation — no other club's data leaks).
        self::assertCount(0, $this->get('/api/teams')['member']);
        self::assertCount(0, $this->get('/api/venues')['member']);
        $me = $this->get('/api/me');
        self::assertFalse($me['club']['onboardingCompleted']);

        // Just-subscribed state: exactly ONE season, current, writable (not
        // read-only), and no validated socle yet (the cockpit gate sends the
        // fresh club to the work-loop). Guards the historical endDate <
        // startDate seed bug too (window must stay coherent).
        self::assertCount(1, $me['seasons']);
        self::assertSame($me['seasons'][0]['id'], $me['currentSeasonId']);
        self::assertTrue($me['seasons'][0]['isCurrent']);
        self::assertFalse($me['seasons'][0]['isReadonly']);
        self::assertNull($me['socleValidatedAt']);
        self::assertGreaterThan($me['seasons'][0]['startDate'], $me['seasons'][0]['endDate']);

        // 3. Minimal data: one team, one gym with a slot, one coach.
        $categoryId = $this->get('/api/sport_categories')['member'][0]['id'];
        $this->post('/api/teams', ['name' => 'SM1', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        self::assertResponseStatusCodeSame(201);

        $venue = $this->post('/api/venues', ['name' => 'Gym A', 'source' => 'manual']);
        self::assertResponseStatusCodeSame(201);
        $this->post('/api/venue_training_slots', ['venueId' => $venue['id'], 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]);
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/coaches', ['firstName' => 'Jean', 'isEmployee' => true]);
        self::assertResponseStatusCodeSame(201);

        // 4. Create a schedule and launch generation.
        $schedule = $this->post('/api/schedules', ['name' => 'Mon planning', 'status' => 'DRAFT']);
        self::assertResponseStatusCodeSame(201);
        $this->post('/api/schedules/' . $schedule['id'] . '/generate', null);
        self::assertResponseStatusCodeSame(202);

        // 5. Onboarding is completed on launch; the schedule is queued.
        self::assertTrue($this->get('/api/me')['club']['onboardingCompleted'], 'launching generation completes onboarding');
        self::assertContains($this->get('/api/schedules/' . $schedule['id'])['status'], ['PENDING', 'GENERATING', 'COMPLETED']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep the same kernel across requests so the JWT auth ordering is exercised.
        self::getContainer()->get(EntityManagerInterface::class);
    }

    private function register(string $ara): string
    {
        $ip = '10.7.' . intdiv(self::$ip, 254) . '.' . (self::$ip % 254 + 1);
        ++self::$ip;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => strtolower($ara) . '@test.fr', 'password' => 'password123',
            'firstName' => 'On', 'lastName' => 'Board', 'ara' => strtoupper($ara), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, ?array $body): array
    {
        $this->client->request('POST', $path, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/ld+json',
        ], null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
