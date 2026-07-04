<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeasonRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeasonRepository::class)]
#[ORM\Table(name: 'season')]
#[ORM\Index(name: 'idx_season_club_status', columns: ['club_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Season implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $endDate;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $exportPdfUrl = null;

    /** The season's chosen/baseline schedule (the "main" plan). First COMPLETED wins, re-designable. */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $baselineScheduleId = null;

    /**
     * Sticky cockpit-unlock milestone: set once when the baseline schedule is
     * first VALIDATED; NEVER reset (reopen does not re-lock the cockpit).
     * See accueil-cockpit-temporel.md §2ter.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $socleValidatedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $transitionData = [];

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

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getExportPdfUrl(): ?string
    {
        return $this->exportPdfUrl;
    }

    public function setExportPdfUrl(?string $exportPdfUrl): self
    {
        $this->exportPdfUrl = $exportPdfUrl;

        return $this;
    }

    public function getBaselineScheduleId(): ?string
    {
        return $this->baselineScheduleId;
    }

    public function getSocleValidatedAt(): ?DateTimeImmutable
    {
        return $this->socleValidatedAt;
    }

    public function setSocleValidatedAt(?DateTimeImmutable $socleValidatedAt): self
    {
        $this->socleValidatedAt = $socleValidatedAt;

        return $this;
    }

    public function setBaselineScheduleId(?string $baselineScheduleId): self
    {
        $this->baselineScheduleId = $baselineScheduleId;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getTransitionData(): array
    {
        return $this->transitionData;
    }

    /** @param array<string, mixed> $transitionData */
    public function setTransitionData(array $transitionData): self
    {
        $this->transitionData = $transitionData;

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
