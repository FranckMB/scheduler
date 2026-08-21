<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * SEC-13 — la saison de la RESSOURCE qu'une écriture cible, résolue HORS filtres.
 *
 * Le garde « saison archivée » ({@see SeasonAccessGuard}) ne voyait que la saison
 * SÉLECTIONNÉE (header `X-Season-Id` ou saison courante). Sans header, la saison
 * courante est writable par définition — mais l'écriture peut viser une ressource
 * qui, elle, vit dans une saison archivée. Ce résolveur rend la saison de la cible.
 *
 * SQL brut FILTER-FREE, exactement le patron de
 * {@see SchedulePlanProvisioner::fetchPlanContext} : la saison de la cible n'est pas
 * forcément la saison filtrée du moment, donc le filtre `season_filter` la cacherait.
 * La RLS scope le club (connexion `app_user` — JAMAIS la connexion `admin` qui la
 * contourne) : une cible d'un AUTRE club rend `null`, et l'appelant retombe sur le
 * header pendant que ses gardes tenant/404 gardent la main. Le garde saison ne doit
 * JAMAIS devenir un oracle d'existence : cible introuvable → `null` → repli.
 */
final class WriteTargetSeasonResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function ofSchedule(string $id): ?string
    {
        return $this->fetchSeasonId('SELECT season_id FROM schedule WHERE id = :id', $id);
    }

    public function ofSchedulePlan(string $id): ?string
    {
        return $this->fetchSeasonId('SELECT season_id FROM schedule_plan WHERE id = :id', $id);
    }

    public function ofScheduleSlot(string $id): ?string
    {
        return $this->fetchSeasonId('SELECT season_id FROM schedule_slot_template WHERE id = :id', $id);
    }

    private function fetchSeasonId(string $sql, string $id): ?string
    {
        // Un id malformé (non-UUID) ne doit pas atteindre Postgres : « invalid input
        // syntax for type uuid » avorterait la transaction sous le harnais de test.
        // Une forme invalide ne peut désigner aucune saison réelle → null → repli.
        if (!$this->isUuid($id)) {
            return null;
        }

        $value = $this->entityManager->getConnection()->fetchOne($sql, ['id' => $id]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
