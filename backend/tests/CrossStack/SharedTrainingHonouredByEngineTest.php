<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P2-27 — axe SÉMANTIQUE (§7.1) : une déclaration de mutualisation doit être HONORÉE par le
 * VRAI solveur, pas seulement acceptée. Deux équipes déclarées ensemble (K=1) atterrissent sur
 * la MÊME case ; le TÉMOIN — le même payload SANS le bloc — les SÉPARE, parce que l'objectif
 * (préférences de gymnase opposées) le préfère. Sans le témoin, un scénario où le solveur
 * co-localise déjà spontanément passerait au vert sans rien prouver.
 *
 * Tourne dans le job CI « Engine semantics » (groupe `contract`), moteur réel ; skip propre s'il
 * est indisponible, comme les autres tests de contrat.
 */
#[Group('contract')]
final class SharedTrainingHonouredByEngineTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';
    private const string V1 = '11111111-1111-4111-8111-111111111111';
    private const string V2 = '22222222-2222-4222-8222-222222222222';
    private const string V3 = '33333333-3333-4333-8333-333333333333';

    public function testTwoDeclaredTeamsAreCoLocatedByTheRealSolver(): void
    {
        // AVEC le bloc : K=1 impose une séance commune → l'unique case partageable (V3, cap 2).
        $withBlock = $this->coLocated($this->solve($this->payload(withSharedGroup: true)));
        self::assertTrue(
            $withBlock,
            'deux équipes déclarées ensemble (K=1) doivent partager une case — le solveur ne l\'honore pas',
        );

        // TÉMOIN — SANS le bloc, les préférences de gymnase opposées les SÉPARENT (V1 vs V2).
        $withoutBlock = $this->coLocated($this->solve($this->payload(withSharedGroup: false)));
        self::assertNotSame(
            $withBlock,
            $withoutBlock,
            'témoin cassé : le solveur co-localise déjà SANS la déclaration — le scénario ne prouve rien',
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function coLocated(array $result): bool
    {
        $cases = [];
        foreach ($result['slots'] as $slot) {
            $cases[$slot['teamId']] = $slot['venueId'] . '|' . $slot['dayOfWeek'] . '|' . substr((string) $slot['startTime'], 0, 5);
        }

        return isset($cases['t1'], $cases['t2']) && $cases['t1'] === $cases['t2'];
    }

    /**
     * Deux équipes (1 séance chacune), deux gymnases capacité 1 le lundi (préférés, opposés) et
     * UN gymnase capacité 2 le mardi (la seule case partageable). Sans mutualisation, chacune va
     * à son gymnase préféré (séparées) ; avec, elles doivent se retrouver sur V3.
     *
     * @return array<string, mixed>
     */
    private function payload(bool $withSharedGroup): array
    {
        $constraints = [
            $this->preferVenue('pref1', 't1', self::V1),
            $this->preferVenue('pref2', 't2', self::V2),
        ];

        $payload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-shared-proof',
            'seasonId' => 'season-shared-proof',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [
                $this->venue(self::V1, [[1, '18:00', 1]]),
                $this->venue(self::V2, [[1, '18:00', 1]]),
                $this->venue(self::V3, [[2, '18:00', 2]]),
            ],
            'coaches' => [],
            'constraints' => $constraints,
            'slotTemplates' => [],
        ];

        if ($withSharedGroup) {
            $payload['sharedTrainings'] = [['id' => 'g', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function preferVenue(string $id, string $teamId, string $venueId): array
    {
        return [
            'id' => $id,
            'scope' => 'TEAM',
            'scopeTargetId' => $teamId,
            'family' => 'FACILITY',
            'ruleType' => 'PREFERRED',
            'name' => 'préférence gymnase',
            'config' => ['preferredVenueId' => $venueId],
            'sortOrder' => 0,
            'isActive' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1, 'isActive' => true];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}> $slots
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, array $slots): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4), 'isActive' => true,
            'trainingSlots' => array_map(
                static fn (array $s): array => ['dayOfWeek' => $s[0], 'startTime' => $s[1], 'durationMinutes' => 90, 'capacity' => $s[2]],
                $slots,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $result = $response->toArray(false);
            self::assertSame('completed', $result['status'], 'le scénario doit rester résoluble');

            return $result;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }
}
