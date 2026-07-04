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

    public function testUnusedSlotRebuiltInFrench(): void
    {
        // Engine sends a raw English "…: no team assigned" message → rebuild locally.
        $diagnostic = [
            'type' => 'unused_slot',
            'venueId' => 'v1',
            'dayOfWeek' => 3,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'message' => 'Gym Foo Wednesday 18:00-19:30: no team assigned',
        ];

        $result = $this->builder->build($diagnostic, [], [], ['v1' => 'Gymnase Foo']);

        self::assertStringContainsString('Gymnase Foo', $result);
        self::assertStringContainsString('mercredi', $result);
        self::assertStringContainsString('18:00', $result);
        self::assertStringContainsString('19:30', $result);
        self::assertStringNotContainsString('no team assigned', $result);
    }

    public function testUnusedSlotSundayUsesFrenchDayName(): void
    {
        // ISO dayOfWeek 7 = dimanche (the engine mislabels it as "7").
        $result = $this->builder->build(
            ['type' => 'unused_slot', 'venueId' => 'v1', 'dayOfWeek' => 7, 'startTime' => '10:00', 'durationMinutes' => 60],
            [],
            [],
            ['v1' => 'Gymnase A'],
        );

        self::assertStringContainsString('dimanche', $result);
        self::assertStringNotContainsString('(7', $result);
    }

    protected function setUp(): void
    {
        $this->builder = new DiagnosticMessageBuilder;
    }
}
