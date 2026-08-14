<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;

/**
 * RÉSOUT les 4 règles implicites « bien-être » d'un club+saison — stocké OU défaut.
 *
 * Source UNIQUE de « ce que valent les règles » : le bloc `implicitRules` du payload solveur
 * ET la collection GET de l'API en dérivent tous deux, ce qui rend « stocké == émis » vrai par
 * construction (gardé par `ImplicitRulePayloadParityTest`). Une clé sans ligne stockée retombe
 * sur le défaut du contrat (`HARD`, seuils historiques) — d'où « absence = défaut, aucun
 * seeding ».
 *
 * La forme rendue par `resolve()` EST celle du bloc payload : `{ruleKey: {intensity, <seuil?>}}`
 * — c'est ce que le moteur lit (aliases Pydantic), donc aucun remappage entre stockage et
 * émission.
 */
final class ImplicitRuleResolver
{
    public function __construct(
        private readonly ImplicitRuleSettingRepository $repository,
    ) {}

    /**
     * Le bloc RÉSOLU tout au défaut — sans base, pour le chemin `build()` en mémoire (tests de
     * contrat cross-stack) où aucun club+saison n'est en jeu.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $resolved = [];
        foreach (ImplicitRuleKey::cases() as $ruleKey) {
            $resolved[$ruleKey->value] = self::defaultEntry($ruleKey);
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    private static function defaultEntry(ImplicitRuleKey $ruleKey): array
    {
        return self::entryFrom($ruleKey, ImplicitRuleIntensity::HARD, null);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private static function entryFrom(ImplicitRuleKey $ruleKey, ImplicitRuleIntensity $intensity, ?array $params): array
    {
        $entry = ['intensity' => $intensity->value];

        $paramKey = $ruleKey->paramKey();
        if (null !== $paramKey) {
            $stored = $params[$paramKey] ?? null;
            $entry[$paramKey] = \is_int($stored) ? $stored : $ruleKey->paramDefault();
        }

        return $entry;
    }

    /**
     * Le bloc RÉSOLU des 4 règles pour un club+saison : réglages stockés par-dessus les défauts.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolve(string $clubId, string $seasonId): array
    {
        $stored = $this->repository->findByClubSeasonIndexed($clubId, $seasonId);

        $resolved = [];
        foreach (ImplicitRuleKey::cases() as $ruleKey) {
            $setting = $stored[$ruleKey->value] ?? null;
            $resolved[$ruleKey->value] = null === $setting
                ? self::defaultEntry($ruleKey)
                : self::entryFrom($ruleKey, $setting->getIntensity(), $setting->getParams());
        }

        return $resolved;
    }
}
