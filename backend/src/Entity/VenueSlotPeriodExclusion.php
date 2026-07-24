<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un créneau de SAISON écarté POUR CETTE PÉRIODE. Réglage sparse : une ligne existe
 * uniquement pour un créneau saisonnier retiré de l'overlay de période ; le créneau
 * saisonnier lui-même n'est JAMAIS supprimé (décision fondateur 2026-07-24 — écarter
 * une semaine ne doit pas détruire la structure de la saison, on doit pouvoir revenir
 * en arrière en supprimant simplement l'exclusion).
 *
 * Ne vise JAMAIS un créneau PRÊTÉ (`venue_training_slot.schedule_plan_id` non nul) :
 * celui-là n'appartient qu'à la période, on le supprime pour de bon.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_slot_period_exclusion')]
#[ORM\UniqueConstraint(name: 'uniq_venue_slot_period_exclusion', columns: ['schedule_plan_id', 'venue_training_slot_id'])]
#[ORM\Index(name: 'idx_venue_slot_period_exclusion_plan', columns: ['schedule_plan_id'])]
class VenueSlotPeriodExclusion implements TenantOwnedInterface
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
    private string $venueTrainingSlotId;

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

    public function getVenueTrainingSlotId(): string
    {
        return $this->venueTrainingSlotId;
    }

    public function setVenueTrainingSlotId(string $venueTrainingSlotId): self
    {
        $this->venueTrainingSlotId = $venueTrainingSlotId;

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
