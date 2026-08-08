<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\EngineClient;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class ContractSchemaTest extends TestCase
{
    private const CLUB_ID = '11111111-1111-1111-1111-111111111111';

    private const SEASON_ID = '22222222-2222-2222-2222-222222222222';

    /**
     * D-17 — cette constante était une COPIE de celle d'`EngineClient`, et le test
     * s'en servait pour poster PUIS pour vérifier l'URL reçue : une tautologie. Passer
     * `EngineClient::ENGINE_URL` à un autre port laissait ces assertions vertes.
     * Elle est désormais LUE sur le client réel — la seule façon d'en faire un garde.
     */
    private static function engineUrl(): string
    {
        $url = new ReflectionClass(EngineClient::class)->getConstant('ENGINE_URL');
        self::assertIsString($url);

        return $url;
    }

    #[Group('phase1')]
    public function testPhase1PayloadShapeIsValidWithoutEngine(): void
    {
        $payload = $this->buildPayload();

        $capturedPayload = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedPayload): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::engineUrl(), $url);
            self::assertArrayHasKey('body', $options);
            self::assertIsString($options['body']);
            $capturedPayload = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse('{"status":"completed","score":0,"slots":[],"diagnostics":[],"metrics":{"solver_version":"test","nb_variables":0,"nb_constraints":0,"nb_conflicts":0,"wall_time_ms":0}}', [
                'http_code' => 200,
            ]);
        });

        $response = $client->request('POST', self::engineUrl(), ['json' => $payload]);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($capturedPayload);
        self::assertSame($payload, $capturedPayload);

        $this->assertPayloadShape($capturedPayload);
    }

    #[Group('contract')]
    public function testContractPostsToRealEngineOrSkipsWhenUnavailable(): void
    {
        $payload = $this->buildPayload();
        $this->assertPayloadShape($payload);

        $client = HttpClient::create(['timeout' => 3]);

        try {
            $response = $client->request('POST', self::engineUrl(), ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            if ($this->isEngineUnavailable($exception)) {
                self::markTestSkipped('Engine not available');
            }

            throw $exception;
        }

        self::assertSame('completed', $data['status']);
        self::assertArrayHasKey('slots', $data);
        self::assertArrayHasKey('diagnostics', $data);
        self::assertArrayHasKey('metrics', $data);
    }

    private function buildPayload(): array
    {
        $venue = (new Venue)
            ->setId('venue-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Venue 1')
            ->setSource('manual')
            ->setIsActive(true);

        $coach = (new Coach)
            ->setId('coach-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setFirstName('Coach')
            ->setLastName('One')
            ->setIsActive(true);

        $team = (new Team)
            ->setId('team-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setSportCategoryId('sport-category-1')
            ->setPriorityTierId(1)
            ->setName('Team 1')
            ->setSessionsPerWeek(2)
            ->setIsActive(true);

        $constraint = (new Constraint)
            ->setId('constraint-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Preferred slot')
            ->setScope(ConstraintScope::TEAM)
            ->setScopeTargetId($team->getId())
            ->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['type' => 'preferred']);

        return new ScheduleConstraintBuilder(new NullLogger)->buildPayload(
            clubId: self::CLUB_ID,
            seasonId: self::SEASON_ID,
            venues: [$venue],
            teams: [$team],
            coaches: [$coach],
            constraints: [$constraint],
        );
    }

    private function assertPayloadShape(array $payload): void
    {
        self::assertSame('2.2', $payload['version']);
        self::assertSame(self::CLUB_ID, $payload['clubId']);
        self::assertSame(self::SEASON_ID, $payload['seasonId']);
        self::assertIsInt($payload['solverSeed']);

        foreach (['version', 'clubId', 'seasonId', 'teams', 'venues', 'coaches', 'constraints'] as $key) {
            self::assertArrayHasKey($key, $payload);
        }

        foreach (['venues', 'teams', 'coaches', 'constraints', 'slotTemplates'] as $key) {
            self::assertArrayHasKey($key, $payload);
            self::assertIsArray($payload[$key]);
        }

        self::assertArrayNotHasKey('venueAvailabilities', $payload);

        self::assertArrayHasKey('id', $payload['venues'][0]);
        self::assertIsString($payload['venues'][0]['id']);
        self::assertSame('manual', $payload['venues'][0]['source']);
        self::assertIsBool($payload['venues'][0]['isActive']);
        self::assertArrayHasKey('trainingSlots', $payload['venues'][0]);
        self::assertIsArray($payload['venues'][0]['trainingSlots']);
        // D-44 — cette branche NE S'EXÉCUTE JAMAIS ici : le fixture construit le builder sans
        // `VenueTrainingSlotRepository` (mode léger, sans DB), donc `trainingSlots` est
        // toujours vide. Elle donnait l'illusion d'une couverture que ce test n'apporte pas.
        // Le contenu des créneaux est réellement gardé là où une DB existe :
        // `ScheduleConstraintBuilderOverlayTest` (blocking, compte les créneaux et leurs jours),
        // `OverlayGenerationTest` (un gymnase fermé sort du payload) et `PeriodPlanBirthTest`.
        // Elle est conservée — et non supprimée — parce qu'elle documente la FORME attendue
        // d'un créneau ; le `if` reste donc, mais son inutilité ici est écrite.
        if ([] !== $payload['venues'][0]['trainingSlots']) {
            $slot = $payload['venues'][0]['trainingSlots'][0];
            self::assertArrayHasKey('dayOfWeek', $slot);
            self::assertArrayHasKey('startTime', $slot);
            self::assertArrayHasKey('durationMinutes', $slot);
            self::assertArrayHasKey('capacity', $slot);
            self::assertArrayNotHasKey('endTime', $slot);
        }
        self::assertArrayNotHasKey('availability', $payload['venues'][0]);

        self::assertArrayHasKey('sportCategoryId', $payload['teams'][0]);
        self::assertSame(1, $payload['teams'][0]['priorityTierId']);
        self::assertSame(2, $payload['teams'][0]['sessionsPerWeek']);
        self::assertIsBool($payload['teams'][0]['isActive']);

        self::assertArrayHasKey('firstName', $payload['coaches'][0]);
        self::assertArrayHasKey('lastName', $payload['coaches'][0]);
        self::assertIsBool($payload['coaches'][0]['isActive']);

        self::assertArrayHasKey('scopeTargetId', $payload['constraints'][0]);
        self::assertSame('TEAM', $payload['constraints'][0]['scope']);
        self::assertSame('TIME', $payload['constraints'][0]['family']);
        self::assertSame('PREFERRED', $payload['constraints'][0]['ruleType']);
        self::assertIsArray($payload['constraints'][0]['config']);
        self::assertArrayNotHasKey('teamId', $payload['constraints'][0]);
        self::assertArrayNotHasKey('type', $payload['constraints'][0]);
        self::assertArrayNotHasKey('severity', $payload['constraints'][0]);
        self::assertArrayNotHasKey('value', $payload['constraints'][0]);
    }

    private function isEngineUnavailable(TransportExceptionInterface $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'connection refused')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'could not connect')
            || str_contains($message, 'unable to connect');
    }
}
