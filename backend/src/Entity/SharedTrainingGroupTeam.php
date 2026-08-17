<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * P2-27 — un membre d'un {@see SharedTrainingGroup}. Le lien groupe → équipe, avec
 * club/saison/plan DÉNORMALISÉS : le listener de péremption ({@see ResourceChangeStaleScheduleListener})
 * et RLS lisent la colonne, jamais une jointure. Écrit une fois, jamais mis à jour (une
 * modification de composition = suppression + recréation des lignes).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shared_training_group_team')]
#[ORM\UniqueConstraint(name: 'uniq_shared_training_group_team', columns: ['group_id', 'team_id'])]
#[ORM\Index(name: 'idx_shared_training_group_team_group', columns: ['group_id'])]
#[ORM\Index(name: 'idx_shared_training_group_team_club_season', columns: ['club_id', 'season_id'])]
class SharedTrainingGroupTeam implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /** Dénormalisé du groupe : NULL = socle saison, non-null = plan de période (ADR-0002). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

    #[ORM\Column(type: 'guid')]
    private string $groupId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    public function __construct()
    {
        $this->id = $this->newUuid();
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

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function setGroupId(string $groupId): self
    {
        $this->groupId = $groupId;

        return $this;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

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
