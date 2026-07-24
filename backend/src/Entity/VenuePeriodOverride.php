<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VenuePeriodMode;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage SPARSE d'un gymnase POUR UNE PÉRIODE. Une ligne existe UNIQUEMENT quand le
 * dirigeant a changé le comportement du gymnase sur cette période — pas de ligne =
 * INHERIT, le gymnase garde ses créneaux de saison.
 *
 * DISABLED = le gymnase ne sert pas du tout cette période.
 * BLANK    = on repart d'une grille vierge (les créneaux de saison sont ignorés) ;
 * seuls les créneaux prêtés du plan restent utilisables.
 *
 * Dans les deux cas le planning principal n'est JAMAIS modifié : c'est l'overlay de
 * période qui lit ces lignes, la structure saisonnière reste intacte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_period_override')]
#[ORM\UniqueConstraint(name: 'uniq_venue_period_override', columns: ['schedule_plan_id', 'venue_id'])]
#[ORM\Index(name: 'idx_venue_period_override_plan', columns: ['schedule_plan_id'])]
class VenuePeriodOverride implements TenantOwnedInterface
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

    /**
     * ADR-0002 inv. 5 — les réglages de période s'accrochent au PLAN, pas au déclencheur
     * calendrier. Aujourd'hui un plan par période (uniq_schedule_plan_calendar_entry), donc
     * l'ancre revient au même ; c'est le découpage hebdomadaire (types-de-planning E1) qui
     * la rend nécessaire : 2 semaines ⇒ 2 plans ⇒ 2 jeux de réglages sur le MÊME déclencheur,
     * que `calendarEntryId` ne saurait pas distinguer.
     */
    #[ORM\Column(type: 'guid')]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    private string $venueId;

    #[ORM\Column(length: 16, enumType: VenuePeriodMode::class)]
    private VenuePeriodMode $mode = VenuePeriodMode::DISABLED;

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

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

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

    public function getMode(): VenuePeriodMode
    {
        return $this->mode;
    }

    public function setMode(VenuePeriodMode $mode): self
    {
        $this->mode = $mode;

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
