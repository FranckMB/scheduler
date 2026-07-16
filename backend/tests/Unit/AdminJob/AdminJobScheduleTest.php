<?php

declare(strict_types=1);

namespace App\Tests\Unit\AdminJob;

use App\AdminJob\AdminJobSchedule;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdminJobScheduleTest extends TestCase
{
    public function testTenMinuteScheduleCatchesUpTheLatestSlotThenMovesForward(): void
    {
        $schedule = AdminJobSchedule::everyTenMinutes();
        $now = new DateTimeImmutable('2026-07-16T10:27:40+02:00');
        $due = $schedule->nextDueAt($now, null);

        self::assertSame('2026-07-16T10:20:00+02:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-07-16T10:30:00+02:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testDailyScheduleCatchesUpAndKeepsParisLocalTimeAcrossDst(): void
    {
        $schedule = AdminJobSchedule::daily(8);
        $now = new DateTimeImmutable('2026-10-25T09:00:00+01:00');
        $due = $schedule->nextDueAt($now, new DateTimeImmutable('2026-10-24T08:00:00+02:00'));

        self::assertSame('2026-10-25T08:00:00+01:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-10-26T08:00:00+01:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testQuarterlyScheduleUsesJanuaryAprilJulyAndOctober(): void
    {
        $schedule = AdminJobSchedule::quarterly(4, 30);
        $now = new DateTimeImmutable('2026-07-16T12:00:00+02:00');
        $due = $schedule->nextDueAt($now, new DateTimeImmutable('2026-04-01T04:30:00+02:00'));

        self::assertSame('2026-07-01T04:30:00+02:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-10-01T04:30:00+02:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testScheduleAlwaysUsesEuropeParis(): void
    {
        $due = AdminJobSchedule::daily(8)->nextDueAt(new DateTimeImmutable('2026-07-16T10:00:00+00:00'), null);

        self::assertSame('Europe/Paris', $due->getTimezone()->getName());
        self::assertSame('2026-07-16T08:00:00+02:00', $due->format(DateTimeInterface::ATOM));
    }

    public function testInvalidWallClockTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminJobSchedule::daily(24);
    }
}
