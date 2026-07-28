<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Le manque de créneaux, dit AVANT la génération (P2-9, volet capacité).
 *
 * Le solveur sait déjà le dire APRÈS coup : `session_below_effective_min` signale
 * une équipe servie en dessous de son minimum. Mais le gestionnaire l'apprend
 * alors au bout d'une génération, sur un planning déjà bancal. Or l'information
 * est calculable sans solveur : c'est une comparaison de deux sommes.
 *
 * ⚠ CE SERVICE NE RECALCULE RIEN. Il lit le payload que `ScheduleConstraintBuilder`
 * expédie réellement au solveur. Les deux premières versions interrogeaient les
 * entités en réimplémentant les filtres du builder, et se trompaient dans les
 * DEUX sens à chaque revue :
 *
 *   - l'offre ignorait que la capacité est bridée à 1 sur un gymnase non
 *     divisible (`buildTrainingSlots`) : on annonçait des places inexistantes ;
 *   - la demande filtrait sur `isActive` alors que le builder expédie TOUTES les
 *     équipes de la saison : on taisait un vrai manque ;
 *   - côté période, ni les gymnases désactivés, ni les jours fermés, ni les
 *     surcharges d'équipe n'étaient pris en compte.
 *
 * Lire la source unique rend ces écarts impossibles par construction. Le payload
 * est mis en cache par le builder, donc l'appel ne coûte pas une reconstruction
 * à chaque validation.
 *
 * ⚠ La condition reste NÉCESSAIRE, pas suffisante : les créneaux sont situés
 * (tel gymnase, tel jour, telle heure) et les équipes ont leurs propres
 * contraintes. Le message annonce donc un plancher (« au moins »), et n'affirme
 * jamais l'inverse.
 */
final class TrainingCapacityChecker
{
    /**
     * @param array<string, mixed> $payload celui que `ScheduleConstraintBuilder`
     *                                      expédie au solveur — jamais un recalcul
     *
     * @return list<string> zéro ou un avertissement, prêt à afficher
     */
    public function warnings(array $payload): array
    {
        $demand = $this->demand($payload);
        if (0 === $demand) {
            return [];
        }

        $supply = $this->supply($payload);
        if ($supply >= $demand) {
            return [];
        }

        $missing = $demand - $supply;

        return [\sprintf(
            'Vos équipes demandent %d créneaux, vos gymnases n’en offrent que %d. '
            . 'Au moins %d séance%s ne pourra%s pas être placée. '
            . 'Ajoutez des créneaux, ou réduisez le nombre de séances de certaines équipes.',
            $demand,
            $supply,
            $missing,
            $missing > 1 ? 's' : '',
            $missing > 1 ? 'ront' : '',
        )];
    }

    /**
     * Somme des séances demandées, telles que le solveur les reçoit.
     *
     * @param array<string, mixed> $payload
     */
    private function demand(array $payload): int
    {
        $total = 0;
        /** @var list<array<string, mixed>> $teams */
        $teams = \is_array($payload['teams'] ?? null) ? $payload['teams'] : [];
        foreach ($teams as $team) {
            $total += (int) ($team['sessionsPerWeek'] ?? 0);
        }

        return $total;
    }

    /**
     * Somme des CAPACITÉS des créneaux du payload, pas leur nombre : un créneau à
     * capacité 2 accueille deux équipes. Ces capacités sont déjà bridées par le
     * builder pour les gymnases non divisibles — les relire ici garantit qu'on
     * compte ce que le solveur verra.
     *
     * @param array<string, mixed> $payload
     */
    private function supply(array $payload): int
    {
        $total = 0;
        /** @var list<array<string, mixed>> $venues */
        $venues = \is_array($payload['venues'] ?? null) ? $payload['venues'] : [];
        foreach ($venues as $venue) {
            /** @var list<array<string, mixed>> $slots */
            $slots = \is_array($venue['trainingSlots'] ?? null) ? $venue['trainingSlots'] : [];
            foreach ($slots as $slot) {
                $total += (int) ($slot['capacity'] ?? 1);
            }
        }

        return $total;
    }
}
