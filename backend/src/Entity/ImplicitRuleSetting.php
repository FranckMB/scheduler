<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le réglage d'UNE règle implicite « bien-être » pour un club+saison (contrat moteur 2.7).
 *
 * ⚠ ABSENCE DE LIGNE = DÉFAUT (HARD, seuils historiques) — aucun seeding. Une ligne n'existe
 * que quand le gestionnaire a CHOISI de dévier du défaut (assouplir en PREFERRED, ou changer
 * un seuil). Le payload est donc RÉSOLU à la lecture (`ImplicitRuleResolver`) : les 4 clés y
 * sont toujours, la ligne stockée l'emportant sur le défaut. Unicité (club, saison, règle).
 */
#[ORM\Entity(repositoryClass: ImplicitRuleSettingRepository::class)]
#[ORM\Table(name: 'implicit_rule_setting')]
#[ORM\UniqueConstraint(name: 'uniq_implicit_rule_club_season_key', columns: ['club_id', 'season_id', 'rule_key'])]
#[ORM\Index(name: 'idx_implicit_rule_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class ImplicitRuleSetting implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(length: 40, enumType: ImplicitRuleKey::class)]
    private ImplicitRuleKey $ruleKey;

    #[ORM\Column(length: 20, enumType: ImplicitRuleIntensity::class)]
    private ImplicitRuleIntensity $intensity = ImplicitRuleIntensity::HARD;

    /**
     * Porte le seuil réglable de la règle (`minRestDays` pour coachRestDay, `maxConsecutive`
     * pour maxConsecutiveSessions). null pour une règle à intensité seule, ou quand le seuil
     * reste au défaut.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $params = null;

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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getRuleKey(): ImplicitRuleKey
    {
        return $this->ruleKey;
    }

    public function setRuleKey(ImplicitRuleKey $ruleKey): self
    {
        $this->ruleKey = $ruleKey;

        return $this;
    }

    public function getIntensity(): ImplicitRuleIntensity
    {
        return $this->intensity;
    }

    public function setIntensity(ImplicitRuleIntensity $intensity): self
    {
        $this->intensity = $intensity;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /** @param array<string, mixed>|null $params */
    public function setParams(?array $params): self
    {
        $this->params = $params;

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
