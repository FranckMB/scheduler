<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Period-editable structure: a SPARSE override of a team's participation for one
 * period (CalendarEntry). A row exists ONLY when the manager changed the team for
 * that period — no row means "seasonal defaults apply". Used by the overlay build
 * to drop deactivated teams and override sessions-per-week; the base plan (and the
 * Team's seasonal fields) are never touched.
 */
#[ORM\Entity]
#[ORM\Table(name: 'team_period_override')]
#[ORM\UniqueConstraint(name: 'uniq_team_period_override', columns: ['calendar_entry_id', 'team_id'])]
#[ORM\Index(name: 'idx_team_period_override_entry', columns: ['calendar_entry_id'])]
class TeamPeriodOverride implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    private string $calendarEntryId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    /** false = the team does NOT train during this period. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /** null = keep the team's seasonal sessionsPerWeek; set = the period-specific volume. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sessionsPerWeek = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getCalendarEntryId(): string
    {
        return $this->calendarEntryId;
    }

    public function setCalendarEntryId(string $calendarEntryId): self
    {
        $this->calendarEntryId = $calendarEntryId;

        return $this;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSessionsPerWeek(): ?int
    {
        return $this->sessionsPerWeek;
    }

    public function setSessionsPerWeek(?int $sessionsPerWeek): self
    {
        $this->sessionsPerWeek = $sessionsPerWeek;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
