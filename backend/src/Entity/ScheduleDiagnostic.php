<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScheduleDiagnosticRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduleDiagnosticRepository::class)]
#[ORM\Table(name: 'schedule_diagnostic')]
#[ORM\Index(name: 'idx_schedule_diagnostic_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_coach', columns: ['coach_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_venue', columns: ['venue_id'])]
#[ORM\HasLifecycleCallbacks]
class ScheduleDiagnostic
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    private string $scheduleId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'string', length: 20)]
    private string $severity;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $teamId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $coachId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $venueId = null;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(type: 'json')]
    private array $suggestions = [];

    public function __construct()
    {
        $this->id = self::newUuid();
        $now = new \DateTimeImmutable();
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getClubId(): string
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

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function setScheduleId(string $scheduleId): self
    {
        $this->scheduleId = $scheduleId;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function getTeamId(): ?string
    {
        return $this->teamId;
    }

    public function setTeamId(?string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function getCoachId(): ?string
    {
        return $this->coachId;
    }

    public function setCoachId(?string $coachId): self
    {
        $this->coachId = $coachId;

        return $this;
    }

    public function getVenueId(): ?string
    {
        return $this->venueId;
    }

    public function setVenueId(?string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    public function setSuggestions(array $suggestions): self
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
