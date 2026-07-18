<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\VenueClosureDays;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — P2-5 5b, axe constraint semantics : les DATES/JOURS réellement fermés d'un
 * gymnase. Pur (pas de DB) → couvre les cas limites que le code-review #263 a levés.
 */
#[Group('phase1')]
final class VenueClosureDaysTest extends TestCase
{
    private const VENUE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testClosedDatesAreTheIncidentIntersectedWithTheWindow(): void
    {
        // Incident jeu 05-07 → dim 05-10 dans une semaine lun 05-04 → dim 05-10.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-05-07', '2026-05-10')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame(['2026-05-07', '2026-05-08', '2026-05-09', '2026-05-10'], array_keys($dates[self::VENUE]));
    }

    public function testMultiWeekWindowDoesNotPhantomTheClosedWeekday(): void
    {
        // LE bug du round 1 : incident du SEUL jeudi 05-07, fenêtre de 3 SEMAINES.
        // Les dates fermées ne doivent contenir QUE 05-07 — pas 05-14 ni 05-21.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-05-07', '2026-05-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-24'),
        );
        self::assertSame(['2026-05-07'], array_keys($dates[self::VENUE]), 'pas de conflit fantôme les jeudis des autres semaines');
        // Le builder, lui, dérive le JOUR (semaine-type) — jeudi (4) fermé sur le bloc.
        $weekdays = VenueClosureDays::closedWeekdaysByVenue(
            [$this->venueClosed('2026-05-07', '2026-05-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-24'),
        );
        self::assertSame([4], array_keys($weekdays[self::VENUE]));
    }

    public function testFullWindowClosureWhenConfigHasNoDates(): void
    {
        // Config nu (legacy) → fermé toute la fenêtre (jamais sous-contraint).
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed(null, null)],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-05'),
        );
        self::assertSame(['2026-05-04', '2026-05-05'], array_keys($dates[self::VENUE]));
    }

    public function testPartialConfigFallsBackToTheWholeWindow(): void
    {
        // Une seule borne (donnée malformée) → toute la fenêtre, jamais sous-contraint.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-05-07', null)],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-05'),
        );
        self::assertSame(['2026-05-04', '2026-05-05'], array_keys($dates[self::VENUE]));
    }

    public function testCalendarInvalidStoredDateDoesNotCrash(): void
    {
        // Date syntaxiquement valide mais impossible (2026-13-45) → pas de crash,
        // fallback toute la fenêtre.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-13-45', '2026-05-10')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-04'),
        );
        self::assertSame(['2026-05-04'], array_keys($dates[self::VENUE]));
    }

    public function testDisjointIncidentClosesNothing(): void
    {
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-06-01', '2026-06-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertArrayNotHasKey(self::VENUE, $dates, 'un incident hors fenêtre ne ferme rien');
    }

    public function testNonClosureFacilityConstraintIsIgnored(): void
    {
        // Une datée FACILITY qui n'est PAS une fermeture (config.type autre) ne ferme rien.
        $forced = $this->venueClosed('2026-05-04', '2026-05-10');
        $forced->setConfig(['type' => 'forced_venue', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        $dates = VenueClosureDays::closedDatesByVenue([$forced], new DateTimeImmutable('2026-05-04'), new DateTimeImmutable('2026-05-10'));
        self::assertArrayNotHasKey(self::VENUE, $dates);
    }

    private function venueClosed(?string $start, ?string $end): Constraint
    {
        $c = new Constraint;
        $c->setFamily(ConstraintFamily::FACILITY);
        $c->setScope(ConstraintScope::FACILITY);
        $c->setScopeTargetId(self::VENUE);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setName('Salle fermée');
        $config = ['type' => 'venue_closed'];
        if (null !== $start) {
            $config['startDate'] = $start;
        }
        if (null !== $end) {
            $config['endDate'] = $end;
        }
        $c->setConfig($config);

        return $c;
    }
}
