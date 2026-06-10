<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DiagnosticMessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @group phase1
 */
final class DiagnosticMessageBuilderTest extends TestCase
{
    public function testUnplacedWithTeamNameReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'unplaced', 'teamId' => 'team-1'],
            teamNames: ['team-1' => 'U13 M3'],
        );

        self::assertSame(
            'U13 M3 n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $message,
        );
    }

    public function testUnplacedWithoutTeamNameFallsBackToGenericLabel(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'unplaced'],
        );

        self::assertSame(
            'L\'équipe n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $message,
        );
    }

    public function testUnplacedWithSnakeCaseTeamId(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'unplaced', 'team_id' => 'team-2'],
            teamNames: ['team-2' => 'U15 F1'],
        );

        self::assertSame(
            'U15 F1 n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $message,
        );
    }

    public function testConflictVenueDoubleBookingReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'conflict', 'venueId' => 'venue-1'],
            venueNames: ['venue-1' => 'Salle B'],
        );

        self::assertSame(
            'Salle B accueille plusieurs équipes simultanément. Veuillez déplacer l\'une des séances.',
            $message,
        );
    }

    public function testConflictCoachDoubleBookingReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'conflict', 'coachId' => 'coach-1'],
            coachNames: ['coach-1' => 'Coach Martin'],
        );

        self::assertSame(
            'Coach Martin est assigné(e) à plusieurs équipes simultanément. Veuillez réattribuer l\'une des séances.',
            $message,
        );
    }

    public function testConflictInfeasibleReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'conflict'],
        );

        self::assertSame(
            'Le planning n\'a pas pu être généré : les contraintes actuelles sont incompatibles.',
            $message,
        );
    }

    public function testCoachOverloadWithCountAndThresholdReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'coach_overload', 'coachId' => 'coach-1', 'count' => 7, 'threshold' => 5],
            coachNames: ['coach-1' => 'Coach Martin'],
        );

        self::assertSame(
            'Coach Martin est surchargé(e) avec 7 séances (limite recommandée : 5).',
            $message,
        );
    }

    public function testCoachOverloadWithoutCountFallsBackToGenericMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'coach_overload', 'coachId' => 'coach-2'],
            coachNames: ['coach-2' => 'Coach Sophie'],
        );

        self::assertSame(
            'Coach Sophie est surchargé(e). Veuillez réduire son nombre de séances.',
            $message,
        );
    }

    public function testSoftLockMovedWithTeamAndVenueReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'soft_lock_moved', 'teamId' => 'team-1', 'venueId' => 'venue-1'],
            teamNames: ['team-1' => 'U13 M3'],
            venueNames: ['venue-1' => 'Salle B'],
        );

        self::assertSame(
            'Le créneau préféré de U13 M3 (Salle B) a été déplacé par le solveur pour un meilleur ajustement global.',
            $message,
        );
    }

    public function testSoftLockMovedWithoutVenueReturnsFrenchBusinessMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'soft_lock_moved', 'teamId' => 'team-2'],
            teamNames: ['team-2' => 'U15 F1'],
        );

        self::assertSame(
            'Le créneau préféré de U15 F1 a été déplacé par le solveur pour un meilleur ajustement global.',
            $message,
        );
    }

    public function testUnknownTypeFallsBackToRawMessage(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'unknown', 'message' => 'Raw technical message.'],
        );

        self::assertSame('Raw technical message.', $message);
    }

    public function testUnknownTypeWithoutMessageReturnsDefault(): void
    {
        $builder = new DiagnosticMessageBuilder();
        $message = $builder->build(
            ['type' => 'unknown'],
        );

        self::assertSame('Diagnostic inconnu.', $message);
    }
}
