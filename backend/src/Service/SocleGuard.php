<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde les actions qui se construisent SUR le calendrier de base de la saison :
 * matchs et plans secondaires (ADR-0002 inv. 13).
 *
 * Le calendrier de base = le plan SEASON **et sa version choisie**. Tant que le
 * gestionnaire n'a choisi aucune version, la saison est un espace de travail : il
 * n'y a rien contre quoi placer un match ni sur quoi bâtir un ajustement.
 *
 * 409 : le pipeline de toasts du frontend le remonte déjà.
 */
final class SocleGuard
{
    public function __construct(
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /** @throws ConflictHttpException tant que le plan de saison n'a pas de version choisie */
    public function assertSeasonPlanChosen(?string $seasonId): void
    {
        if (null === $seasonId) {
            return; // aucune saison résolue → d'autres gardes s'en chargent
        }

        if (null === $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId)) {
            throw new ConflictHttpException('Validez le planning principal avant de créer un match ou un planning secondaire.');
        }
    }

    /**
     * Miroir EXACT de {@see assertSeasonPlanChosen}, appliqué à l'INVERSE : les deux
     * coexistent parce qu'un même geste ne peut jamais requérir les deux à la fois —
     * bâtir SUR le socle (match, overlay de période) exige qu'il soit pointé, préparer
     * un NOUVEAU socle de saison (generate/regenerate/POST version) exige qu'il ne le
     * soit pas (tant qu'il est en vigueur, on le rouvre avant d'en refaire un autre).
     *
     * @throws ConflictHttpException dès que le plan de saison a une version choisie
     */
    public function assertSeasonPlanNotChosen(?string $seasonId): void
    {
        if (null === $seasonId) {
            return; // aucune saison résolue → d'autres gardes s'en chargent
        }

        if (null !== $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId)) {
            throw new ConflictHttpException('Le planning de la saison est en vigueur — rouvrez-le avant d\'en préparer un autre.');
        }
    }
}
