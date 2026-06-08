<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'plan')]
#[ORM\HasLifecycleCallbacks]
class Plan
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

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $maxTeams;

    #[ORM\Column(type: 'integer')]
    private int $maxVenues;

    #[ORM\Column(type: 'integer')]
    private int $maxGenerations;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $monthlyPrice;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $annualPrice;

    #[ORM\Column(type: 'json')]
    private array $features = [];

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getMaxTeams(): int
    {
        return $this->maxTeams;
    }

    public function setMaxTeams(int $maxTeams): self
    {
        $this->maxTeams = $maxTeams;

        return $this;
    }

    public function getMaxVenues(): int
    {
        return $this->maxVenues;
    }

    public function setMaxVenues(int $maxVenues): self
    {
        $this->maxVenues = $maxVenues;

        return $this;
    }

    public function getMaxGenerations(): int
    {
        return $this->maxGenerations;
    }

    public function setMaxGenerations(int $maxGenerations): self
    {
        $this->maxGenerations = $maxGenerations;

        return $this;
    }

    public function getMonthlyPrice(): string
    {
        return $this->monthlyPrice;
    }

    public function setMonthlyPrice(string|int|float $monthlyPrice): self
    {
        $this->monthlyPrice = (string) $monthlyPrice;

        return $this;
    }

    public function getAnnualPrice(): string
    {
        return $this->annualPrice;
    }

    public function setAnnualPrice(string|int|float $annualPrice): self
    {
        $this->annualPrice = (string) $annualPrice;

        return $this;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function setFeatures(array $features): self
    {
        $this->features = $features;

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
