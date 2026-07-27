<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Team;
use App\Entity\VenueTrainingSlot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le manque de créneaux, dit AVANT la génération (P2-9, volet capacité).
 *
 * Le solveur sait déjà le dire APRÈS coup : `session_below_effective_min` signale
 * une équipe servie en dessous de son minimum. Mais le gestionnaire l'apprend
 * alors au bout d'une génération, sur un planning déjà bancal. Or l'information
 * est calculable sans solveur : c'est une comparaison de deux sommes.
 *
 * Ce contrôle ne DUPLIQUE pas la règle du solveur, il la devance. À noter que
 * `sessionsPerWeek` n'est délibérément PAS un plancher dur côté engine
 * (`main.py` met tous les minimums à zéro, justement parce qu'un plancher dur
 * ferait sortir le solveur en UNKNOWN quand la capacité manque) : c'est une
 * demande, pas une garantie. D'où un avertissement, jamais une erreur.
 *
 * ⚠ La condition est NÉCESSAIRE, pas suffisante. Les créneaux sont situés (tel
 * gymnase, tel jour, telle heure) et les équipes ont leurs propres contraintes :
 * la passer ne prouve rien. Le message n'annonce donc jamais que « ça passe », et
 * son chiffre est présenté comme un plancher (« au moins »).
 */
final class TrainingCapacityChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param string|null $schedulePlanId Ancre de période ; `null` = la grille de saison
     *
     * @return list<string> zéro ou un avertissement, prêt à afficher
     */
    public function warnings(string $clubId, string $seasonId, ?string $schedulePlanId = null): array
    {
        // PÉRIODE : on se tait, volontairement (revue #317). Une période filtre son
        // offre ET sa demande — `buildForOverlay` écarte les gymnases désactivés
        // (`VenuePeriodOverride`) et les jours fermés, puis retire les équipes
        // désactivées et applique leurs `sessionsPerWeek` de période
        // (`TeamPeriodOverride`). Recopier ces règles ici, c'est le miroir qui
        // dérive : la première version surestimait l'offre (silence sur un vrai
        // manque) ET la demande (fausse alerte sur une période parfaitement
        // dimensionnée) — les deux à la fois.
        //
        // S'appuyer sur la source unique est impossible en l'état : `buildForOverlay`
        // exige un `Schedule`, qui n'existe pas AVANT la génération. Tant que ce
        // chemin n'est pas ouvert, mieux vaut ne rien dire que dire faux.
        if (null !== $schedulePlanId) {
            return [];
        }

        $demand = $this->demand($clubId, $seasonId);
        if (0 === $demand) {
            return [];
        }

        $supply = $this->supply($clubId, $seasonId);
        if ($supply >= $demand) {
            return [];
        }

        return [\sprintf(
            'Vos équipes demandent %d créneaux, vos gymnases n’en offrent que %d. '
            . 'Au moins %d séance%s ne pourra%s pas être placée. '
            . 'Ajoutez des créneaux, ou réduisez le nombre de séances de certaines équipes.',
            $demand,
            $supply,
            $demand - $supply,
            $demand - $supply > 1 ? 's' : '',
            $demand - $supply > 1 ? 'ront' : '',
        )];
    }

    /** Somme des séances demandées par les équipes ACTIVES. */
    private function demand(string $clubId, string $seasonId): int
    {
        $total = 0;
        foreach ($this->entityManager->getRepository(Team::class)->findBy([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'isActive' => true,
        ]) as $team) {
            // `sessionsPerWeek` — ce que l'équipe DEMANDE, pas son plancher.
            // Retenir `minSessionsOverride` sous-estimait la demande : le solveur
            // place jusqu'à `sessionsPerWeek`, donc une équipe visant 3 séances
            // avec un minimum de 1 masquait deux séances de besoin réel.
            $total += $team->getSessionsPerWeek();
        }

        return $total;
    }

    /**
     * Somme des CAPACITÉS des créneaux, pas leur nombre : un créneau à capacité 2
     * accueille deux équipes, le compter pour 1 sous-estimerait l'offre et ferait
     * crier au loup.
     */
    private function supply(string $clubId, string $seasonId): int
    {
        $total = 0;
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'schedulePlanId' => null, // la grille de SAISON : `null` = ligne de base partagée
        ]) as $slot) {
            $total += $slot->getCapacity();
        }

        return $total;
    }
}
