<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * UNE CONTRAINTE QUI CHANGE PÉRIME LES PLANNINGS DÉJÀ GÉNÉRÉS — et il faut le DIRE.
 *
 * Le bug de confiance : passer une contrainte en HARD après coup, puis regarder le planning
 * d'avant (résolu quand la règle était encore PREFERRED). Le moteur avait raison de ne rien
 * dire — son résultat n'est pas FAUX, il est PÉRIMÉ : il décrit un état antérieur des règles.
 * L'application, elle, mentait par omission en l'affichant comme s'il décrivait les données
 * courantes. On pose donc un marqueur, jumeau de « retouché à la main » (F2b), avec un autre
 * déclencheur.
 *
 * ⚠ **Listener d'entité, PAS marquage par appelant.** Les contraintes s'écrivent depuis
 * plusieurs endroits — `ConstraintStateProcessor` (API), `CalendarEntryStateProcessor`
 * (contraintes datées), `ManualEditService`, `DefaultConstraintSeeder`, des commandes, ou un
 * `EntityManager` nu. Marquer chaque appelant garantirait d'en oublier un, et un oubli ici
 * c'est le bug qui revient silencieusement. Le listener attrape tout writer (patron maison :
 * `TeamTagSyncListener`).
 *
 * ⚠ **Scope tenant + saison.** On ne marque QUE les plannings du club ET de la saison de la
 * contrainte (WHERE explicite ci-dessous). RLS borne déjà le club en défense en profondeur ;
 * c'est la saison qui se perdrait sans le filtre, et un listener trop large serait une fuite
 * inter-clubs / inter-saisons.
 *
 * ⚠ **Seuls les plannings COMPLETED sont marqués.** Un DRAFT/PENDING/GENERATING/FAILED ne
 * porte aucun résultat de solveur qui puisse mentir sur sa fraîcheur — il n'y a rien à
 * périmer. On marque en revanche TOUTES les versions COMPLETED (pas seulement celle en
 * vigueur) : le gestionnaire consulte aussi les versions antérieures et les overlays
 * (sélecteur de version, « Charger cette version »), toutes résolues contre des règles plus
 * anciennes. Sous-marquer, c'est laisser revenir le bug.
 *
 * ⚠ **On ne couple PAS le marquage au « quelles contraintes touchent quels plans »**
 * (permanente ⇒ socle SEASON, datée ⇒ overlay de sa période). Cette dérivation serait un
 * couplage à tenir, et le jour où elle se trompe c'est un oubli SILENCIEUX — exactement le
 * défaut qu'on répare. Re-marquer largement est réversible et bon marché : une (re)génération
 * remet le marqueur à faux (le planning redevient fidèle). Un faux « périmé » sur un plan non
 * concerné coûte une régénération inutile ; un « périmé » manqué coûte la confiance.
 */
#[AsEntityListener(event: Events::postPersist, method: 'constraintTouched', entity: Constraint::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'constraintTouched', entity: Constraint::class)]
#[AsEntityListener(event: Events::postRemove, method: 'constraintTouched', entity: Constraint::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ConstraintChangeStaleScheduleListener
{
    /** @var array<string, array{clubId: string, seasonId: string}> paires (club, saison) à marquer, dédupliquées par clé */
    private array $pending = [];

    public function constraintTouched(Constraint $constraint): void
    {
        // La suppression garde clubId/seasonId lisibles : le planning résolu AVEC cette
        // contrainte est périmé du seul fait qu'elle a disparu.
        $this->pending[$constraint->getClubId() . ':' . $constraint->getSeasonId()] = [
            'clubId' => $constraint->getClubId(),
            'seasonId' => $constraint->getSeasonId(),
        ];
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        // Vidé AVANT toute écriture : ce listener n'appelle pas flush() (bulk UPDATE via
        // execute(), qui ne redéclenche pas postFlush), donc pas de récursion — mais on vide
        // quand même en tête pour rester robuste si un autre listener flushe derrière nous.
        $this->pending = [];

        if ([] === $pending) {
            return;
        }

        $manager = $args->getObjectManager();
        foreach ($pending as $scope) {
            // Bulk UPDATE : on ne charge pas les plannings (ils peuvent être nombreux), on
            // pose le drapeau en une requête. Le WHERE explicite (club + saison + COMPLETED)
            // EST la frontière ; RLS la double côté base.
            $manager->createQuery(
                'UPDATE ' . Schedule::class . ' s'
                . ' SET s.constraintsChangedSinceGeneration = true'
                . ' WHERE s.clubId = :clubId AND s.seasonId = :seasonId AND s.status = :status',
            )
                ->setParameter('clubId', $scope['clubId'])
                ->setParameter('seasonId', $scope['seasonId'])
                ->setParameter('status', ScheduleStatus::COMPLETED)
                ->execute();
        }
    }
}
