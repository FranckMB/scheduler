<?php

declare(strict_types=1);

namespace App\AdminJob;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final readonly class AdminJobSchedule
{
    private const TIMEZONE = 'Europe/Paris';

    private function __construct(public string $cadence, private int $hour = 0, private int $minute = 0)
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('A job schedule time must be a valid hour and minute.');
        }
    }

    public static function everyTenMinutes(): self
    {
        return new self('every_10_minutes');
    }

    public static function daily(int $hour, int $minute = 0): self
    {
        return new self('daily', $hour, $minute);
    }

    public static function quarterly(int $hour, int $minute = 0): self
    {
        return new self('quarterly', $hour, $minute);
    }

    public function nextDueAt(DateTimeImmutable $now, ?DateTimeImmutable $lastScheduledFor): DateTimeImmutable
    {
        $due = $this->mostRecentDueAt($now);
        if (!$lastScheduledFor instanceof DateTimeImmutable || $lastScheduledFor < $due) {
            return $due;
        }

        return match ($this->cadence) {
            'every_10_minutes' => $due->modify('+10 minutes'),
            'daily' => $due->modify('+1 day'),
            'quarterly' => $due->modify('+3 months'),
            default => throw new LogicException(\sprintf('Unsupported admin job cadence "%s".', $this->cadence)),
        };
    }

    private function mostRecentDueAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $local = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        if ('every_10_minutes' === $this->cadence) {
            return $local->setTime((int) $local->format('H'), intdiv((int) $local->format('i'), 10) * 10);
        }

        if ('daily' === $this->cadence) {
            $candidate = $local->setTime($this->hour, $this->minute);

            return $candidate <= $local ? $candidate : $candidate->modify('-1 day');
        }

        $year = (int) $local->format('Y');
        foreach ([10, 7, 4, 1] as $month) {
            $candidate = $local->setDate($year, $month, 1)->setTime($this->hour, $this->minute);
            if ($candidate <= $local) {
                return $candidate;
            }
        }

        return $local->setDate($year - 1, 10, 1)->setTime($this->hour, $this->minute);
    }
}
