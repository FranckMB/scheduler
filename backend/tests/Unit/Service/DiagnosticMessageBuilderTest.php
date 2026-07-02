<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DiagnosticMessageBuilder;
use PHPUnit\Framework\TestCase;

final class DiagnosticMessageBuilderTest extends TestCase
{
    private DiagnosticMessageBuilder $builder;

    public function testPrefersPreciseEngineMessageForConflict(): void
    {
        // The engine (S5) already names venue + teams + day + time. That precise
        // message must reach the manager, not be flattened by a local rebuild.
        $diagnostic = [
            'type' => 'conflict',
            'venueId' => 'v1',
            'message' => 'Le gymnase Léo Lagrange accueille 2 équipes en même temps le mardi 18:00–19:30 alors que sa capacité est de 1 : Séniors M1, U15 F.',
        ];

        $result = $this->builder->build($diagnostic, [], [], ['v1' => 'Léo Lagrange']);

        self::assertStringContainsString('mardi', $result);
        self::assertStringContainsString('Séniors M1', $result);
        self::assertStringContainsString('U15 F', $result);
    }

    public function testFallsBackToLocalisedMessageWhenEngineMessageMissing(): void
    {
        $diagnostic = ['type' => 'conflict', 'venueId' => 'v1'];

        $result = $this->builder->build($diagnostic, [], [], ['v1' => 'Gymnase A']);

        self::assertStringContainsString('Gymnase A', $result);
        self::assertStringContainsString('plusieurs équipes', $result);
    }

    public function testSoftLockMovedAlwaysRebuiltLocally(): void
    {
        // Engine still emits a raw English message for this type → rebuild locally.
        $diagnostic = [
            'type' => 'soft_lock_moved',
            'teamId' => 't1',
            'venueId' => 'v1',
            'message' => 'The preferred slot for team t1 at v1 on day 2 was moved.',
        ];

        $result = $this->builder->build($diagnostic, ['t1' => 'SM1'], [], ['v1' => 'Gymnase A']);

        self::assertStringContainsString('SM1', $result);
        self::assertStringContainsString('déplacé', $result);
        self::assertStringNotContainsString('preferred slot', $result);
    }

    protected function setUp(): void
    {
        $this->builder = new DiagnosticMessageBuilder;
    }
}
