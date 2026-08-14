<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImplicitRuleSetting;
use App\Enum\ImplicitRuleKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImplicitRuleSetting>
 */
final class ImplicitRuleSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImplicitRuleSetting::class);
    }

    /**
     * Les réglages stockés d'un club+saison, indexés par la clé de règle. Une clé absente
     * signifie « au défaut » (le résolveur y pourvoit).
     *
     * @return array<string, ImplicitRuleSetting>
     */
    public function findByClubSeasonIndexed(string $clubId, string $seasonId): array
    {
        $indexed = [];
        foreach ($this->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $setting) {
            $indexed[$setting->getRuleKey()->value] = $setting;
        }

        return $indexed;
    }

    public function findOneByClubSeasonKey(string $clubId, string $seasonId, ImplicitRuleKey $ruleKey): ?ImplicitRuleSetting
    {
        return $this->findOneBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'ruleKey' => $ruleKey]);
    }
}
