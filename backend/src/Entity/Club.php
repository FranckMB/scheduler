<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClubRepository::class)]
#[ORM\Table(name: 'club')]
#[ORM\Index(name: 'idx_club_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_club_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_club_ffbb_club_code', columns: ['ffbb_club_code'])]
#[ORM\HasLifecycleCallbacks]
class Club
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

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(type: 'string', length: 180)]
    private string $slug;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $planId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $billingCycle = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $planExpiresAt = null;

    #[ORM\Column(type: 'integer')]
    private int $generationCountSeason = 0;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $schoolZone = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $timezone = 'Europe/Paris';

    #[ORM\Column(type: 'string', length: 10)]
    private string $locale = 'fr';

    #[ORM\Column(type: 'boolean')]
    private bool $onboardingCompleted = false;

    #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
    private ?string $ffbbClubCode = null;

    /** Club accent colour (hex, e.g. #3498DB) driving the UI `--accent`. */
    #[ORM\Column(type: 'string', length: 9, nullable: true)]
    private ?string $accentColor = null;

    /**
     * Up to 3 hex colours extracted from the club logo, used to tint the theme.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $accentPalette = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPlanId(): ?int
    {
        return $this->planId;
    }

    public function setPlanId(?int $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function getBillingCycle(): ?string
    {
        return $this->billingCycle;
    }

    public function setBillingCycle(?string $billingCycle): self
    {
        $this->billingCycle = $billingCycle;

        return $this;
    }

    public function getPlanExpiresAt(): ?DateTimeImmutable
    {
        return $this->planExpiresAt;
    }

    public function setPlanExpiresAt(?DateTimeImmutable $planExpiresAt): self
    {
        $this->planExpiresAt = $planExpiresAt;

        return $this;
    }

    public function getGenerationCountSeason(): int
    {
        return $this->generationCountSeason;
    }

    public function setGenerationCountSeason(int $generationCountSeason): self
    {
        $this->generationCountSeason = $generationCountSeason;

        return $this;
    }

    public function getSchoolZone(): ?string
    {
        return $this->schoolZone;
    }

    public function setSchoolZone(?string $schoolZone): self
    {
        $this->schoolZone = $schoolZone;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    public function isOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    public function setOnboardingCompleted(bool $onboardingCompleted): self
    {
        $this->onboardingCompleted = $onboardingCompleted;

        return $this;
    }

    public function getFfbbClubCode(): ?string
    {
        return $this->ffbbClubCode;
    }

    public function setFfbbClubCode(?string $ffbbClubCode): self
    {
        $this->ffbbClubCode = $ffbbClubCode;

        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): self
    {
        $this->accentColor = $accentColor;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAccentPalette(): ?array
    {
        return $this->accentPalette;
    }

    /**
     * @param list<string>|null $accentPalette
     */
    public function setAccentPalette(?array $accentPalette): self
    {
        $this->accentPalette = $accentPalette;

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
