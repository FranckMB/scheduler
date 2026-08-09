<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Club;
use App\Service\ClubDay;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * P4-46 — « quel jour est-on pour ce club ? », les quatre cas.
 *
 * ⚑ Le cas guadeloupéen est celui qui a motivé le foyer : le soir du dernier jour d'une
 * deadline, il est déjà demain à Paris — comparer au jour du SERVEUR fermait le lien
 * (`410`) alors que la règle promet « deadline incluse, le jour même est ouvert ». Le jour
 * qui compte est celui que LE CLUB vit.
 */
#[Group('phase1')]
final class ClubDayTest extends TestCase
{
    /** 01:30 UTC le 16 : déjà le 16 à Paris… encore le 15 en Guadeloupe (UTC−4). */
    public function testAGuadeloupeClubIsStillOnYesterdayWhenParisHasMovedOn(): void
    {
        $service = new ClubDay(new MockClock('2026-06-16 01:30:00', 'UTC'));

        self::assertSame('2026-06-15', $service->todayYmdFor($this->club('America/Guadeloupe')), 'le soir guadeloupéen appartient encore à SA journée — pas à celle de Paris');
        self::assertSame('2026-06-16', $service->todayYmdFor($this->club('Europe/Paris')));
    }

    /** 20:00 UTC le 15 : encore le 15 à Paris… déjà le 16 à Nouméa (UTC+11). */
    public function testANoumeaClubIsAlreadyOnTomorrow(): void
    {
        $service = new ClubDay(new MockClock('2026-06-15 20:00:00', 'UTC'));

        self::assertSame('2026-06-16', $service->todayYmdFor($this->club('Pacific/Noumea')));
        self::assertSame('2026-06-15', $service->todayYmdFor($this->club('Europe/Paris')));
    }

    public function testAnEmptyTimezoneFallsBackToParis(): void
    {
        $service = new ClubDay(new MockClock('2026-06-15 23:30:00', 'UTC'));

        // 23:30 UTC = 01:30 le 16 à Paris : le repli doit être Paris, pas UTC.
        self::assertSame('2026-06-16', $service->todayYmdFor($this->club('')));
    }

    /** Une TZ stockée invalide ne doit JAMAIS exploser sur une donnée — repli silencieux. */
    public function testAnInvalidTimezoneFallsBackToParisWithoutThrowing(): void
    {
        $service = new ClubDay(new MockClock('2026-06-15 12:00:00', 'UTC'));

        self::assertSame('2026-06-15', $service->todayYmdFor($this->club('Mars/Olympus')));
    }

    private function club(string $timezone): Club
    {
        return new Club()->setTimezone($timezone);
    }
}
