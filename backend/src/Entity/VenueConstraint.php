<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueConstraintRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VenueConstraintRepository::class)]
#[ORM\Table(name: 'venue_constraint')]
#[ORM\Index(name: 'idx_venue_constraint_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_venue_constraint_venue', columns: ['venue_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueConstraint
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
    private string $venueId;

    #[ORM\Column(type: 'string', length: 30)]
    private string $constraintType;

    #[ORM\Column(type: 'string', length: 20)]
    private string $constraintValue;

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

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getConstraintType(): string
    {
        return $this->constraintType;
    }

    public function setConstraintType(string $constraintType): self
    {
        $this->constraintType = $constraintType;

        return $this;
    }

    public function getConstraintValue(): string
    {
        return $this->constraintValue;
    }

    public function setConstraintValue(string $constraintValue): self
    {
        $this->constraintValue = $constraintValue;

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
