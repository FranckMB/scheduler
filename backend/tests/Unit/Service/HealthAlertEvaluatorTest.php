<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\HealthAlertEvaluator;
use PHPUnit\Framework\TestCase;

final class HealthAlertEvaluatorTest extends TestCase
{
    private HealthAlertEvaluator $evaluator;

    public function testAllGreenYieldsNoAlert(): void
    {
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 50, 'infeasible24h' => 2]));
    }

    public function testEachDownServiceFires(): void
    {
        $health = $this->healthyHealth();
        $health['services']['engine']['status'] = 'down';
        $health['services']['worker']['status'] = 'unknown';

        $keys = array_column($this->evaluator->evaluate($health, $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0]), 'key');
        self::assertSame(['service:engine', 'service:worker'], $keys);
    }

    public function testMessengerBacklogAndFailedFire(): void
    {
        $health = $this->healthyHealth();
        $health['messenger']['backlog'] = 101;
        $health['messenger']['failed'] = 3;

        $keys = array_column($this->evaluator->evaluate($health, $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0]), 'key');
        self::assertSame(['messenger-backlog', 'messenger-failed'], $keys);
    }

    public function testInfeasibleRateNeedsBothRateAndVolume(): void
    {
        // 2/3 infaisables mais SOUS le plancher de volume → silence (jamais d'alerte à vide).
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 3, 'infeasible24h' => 2]));
        // Plancher atteint + taux > 50 % → alerte.
        $keys = array_column($this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 10, 'infeasible24h' => 6]), 'key');
        self::assertSame(['infeasible-rate'], $keys);
        // Volume élevé mais taux sous le seuil → silence.
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 100, 'infeasible24h' => 50]));
    }

    public function testStaleReferentialFires(): void
    {
        $freshness = $this->freshFreshness();
        $freshness[1]['stale'] = true;

        $alerts = $this->evaluator->evaluate($this->healthyHealth(), $freshness, ['generations24h' => 0, 'infeasible24h' => 0]);
        self::assertSame(['freshness:public-holidays'], array_column($alerts, 'key'));
        self::assertStringContainsString('Jours fériés', $alerts[0]['message']);
    }

    protected function setUp(): void
    {
        $this->evaluator = new HealthAlertEvaluator;
    }

    /** @return array<string, mixed> */
    private function healthyHealth(): array
    {
        return [
            'services' => [
                'database' => ['status' => 'up'],
                'redis' => ['status' => 'up'],
                'engine' => ['status' => 'up'],
                'mercure' => ['status' => 'up'],
                'worker' => ['status' => 'up'],
            ],
            'messenger' => ['status' => 'up', 'backlog' => 3, 'failed' => 0],
        ];
    }

    /** @return list<array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}> */
    private function freshFreshness(): array
    {
        return [
            ['key' => 'school-holidays', 'label' => 'Vacances scolaires', 'lastUpdatedAt' => '2026-07-01', 'staleAfterDays' => 100, 'stale' => false],
            ['key' => 'public-holidays', 'label' => 'Jours fériés', 'lastUpdatedAt' => '2026-07-01', 'staleAfterDays' => 100, 'stale' => false],
        ];
    }
}
