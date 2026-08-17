<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * P2-27 — mutualisation : N équipes déclarées s'entraînent ENSEMBLE, avec EXACTEMENT
 * ``commonSessions`` séances partagées (même gymnase, même jour, même heure).
 *
 * ADR-0002 inv. 5 — la déclaration s'accroche au PLAN : ``schedulePlanId`` NULLABLE (patron
 * ``Reservation``/``VenueTrainingSlot``). NULL = socle saison (base plan) ; non-null = plan de
 * période. Un plan de période naît SANS déclaration (patron ``Reservation``, pas patron grille
 * copiée). Les membres vivent dans ``SharedTrainingGroupTeam`` (club/saison/plan dénormalisés).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shared_training_group')]
#[ORM\Index(name: 'idx_shared_training_group_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_shared_training_group_plan', columns: ['schedule_plan_id'])]
class SharedTrainingGroup implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /** NULL = socle saison (base plan) ; non-null = plan de période (ADR-0002 inv. 5). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

    /** Nombre EXACT de séances partagées par le groupe (≥ 1, borné à la saisie par le min sessionsPerWeek). */
    #[ORM\Column(type: 'integer')]
    private int $commonSessions = 1;

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

    public function touch(): self
    {
        $this->updatedAt = new DateTimeImmutable;

        return $this;
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

    public function getSchedulePlanId(): ?string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(?string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getCommonSessions(): int
    {
        return $this->commonSessions;
    }

    public function setCommonSessions(int $commonSessions): self
    {
        $this->commonSessions = $commonSessions;

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
