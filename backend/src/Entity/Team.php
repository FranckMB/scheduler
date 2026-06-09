<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'team')]
#[ORM\Index(name: 'idx_team_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_team_sport_category', columns: ['sport_category_id'])]
#[ORM\Index(name: 'idx_team_priority_tier', columns: ['priority_tier_id'])]
#[ORM\Index(name: 'idx_team_forced_venue', columns: ['forced_venue_id'])]
#[ORM\Index(name: 'idx_team_parent', columns: ['parent_team_id'])]
#[ORM\HasLifecycleCallbacks]
class Team
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
    private string $sportCategoryId;

    #[ORM\Column(type: 'integer')]
    private int $priorityTierId;

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(type: 'smallint')]
    private int $sessionsPerWeek;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $minSessionsOverride = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $matchDay = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $forcedVenueId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parentTeamId = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $ffbbTeamId = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
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

    public function getSportCategoryId(): string
    {
        return $this->sportCategoryId;
    }

    public function setSportCategoryId(string $sportCategoryId): self
    {
        $this->sportCategoryId = $sportCategoryId;

        return $this;
    }

    public function getPriorityTierId(): int
    {
        return $this->priorityTierId;
    }

    public function setPriorityTierId(int $priorityTierId): self
    {
        $this->priorityTierId = $priorityTierId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): self
    {
        $this->gender = $gender;

        return $this;
    }

    public function getSessionsPerWeek(): int
    {
        return $this->sessionsPerWeek;
    }

    public function setSessionsPerWeek(int $sessionsPerWeek): self
    {
        $this->sessionsPerWeek = $sessionsPerWeek;

        return $this;
    }

    public function getMinSessionsOverride(): ?int
    {
        return $this->minSessionsOverride;
    }

    public function setMinSessionsOverride(?int $minSessionsOverride): self
    {
        $this->minSessionsOverride = $minSessionsOverride;

        return $this;
    }

    public function getMatchDay(): ?int
    {
        return $this->matchDay;
    }

    public function setMatchDay(?int $matchDay): self
    {
        $this->matchDay = $matchDay;

        return $this;
    }

    public function getForcedVenueId(): ?string
    {
        return $this->forcedVenueId;
    }

    public function setForcedVenueId(?string $forcedVenueId): self
    {
        $this->forcedVenueId = $forcedVenueId;

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getParentTeamId(): ?string
    {
        return $this->parentTeamId;
    }

    public function setParentTeamId(?string $parentTeamId): self
    {
        $this->parentTeamId = $parentTeamId;

        return $this;
    }

    public function getFfbbTeamId(): ?string
    {
        return $this->ffbbTeamId;
    }

    public function setFfbbTeamId(?string $ffbbTeamId): self
    {
        $this->ffbbTeamId = $ffbbTeamId;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
