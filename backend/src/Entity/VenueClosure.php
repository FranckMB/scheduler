<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueClosureRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VenueClosureRepository::class)]
#[ORM\Table(name: 'venue_closure')]
#[ORM\Index(name: 'idx_venue_closure_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_venue_closure_venue', columns: ['venue_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueClosure
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

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateStart;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateEnd;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

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

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getDateStart(): \DateTimeImmutable
    {
        return $this->dateStart;
    }

    public function setDateStart(\DateTimeImmutable $dateStart): self
    {
        $this->dateStart = $dateStart;

        return $this;
    }

    public function getDateEnd(): \DateTimeImmutable
    {
        return $this->dateEnd;
    }

    public function setDateEnd(\DateTimeImmutable $dateEnd): self
    {
        $this->dateEnd = $dateEnd;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

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
