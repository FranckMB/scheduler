<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\TeamTagService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Team::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Team::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'categoryUpdated', entity: SportCategory::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TeamTagSyncListener
{
    /** @var list<Team> */
    private array $pendingTeams = [];

    /** @var list<string> ids de catégories éditées, résolus en équipes au postFlush */
    private array $pendingCategoryIds = [];

    public function __construct(
        private readonly TeamTagService $teamTagService,
    ) {}

    public function postPersist(Team $team): void
    {
        $this->pendingTeams[] = $team;
    }

    public function postUpdate(Team $team): void
    {
        $this->pendingTeams[] = $team;
    }

    /**
     * P4-50 (b) — LES TAGS D'UNE ÉQUIPE NE DÉPENDENT PAS QUE DE L'ÉQUIPE.
     *
     * `TeamTagService::determineTagNames` lit le NOM et les bornes d'âge de la
     * `SportCategory` (`TeamTagService.php:349-374`) : renommer « U11 » en « U13 », ou
     * corriger un `ageMax`, change les tags de TOUTES ses équipes. Or ce listener
     * n'écoutait que `Team` — la correction de catégorie laissait donc les tags périmés,
     * et **aucun écran ne le disait**.
     *
     * Ce n'est pas cosmétique : `ScheduleConstraintBuilder::resolveTagToTeamIds` éclate un
     * `targetTag` en N contraintes par équipe dans le payload. Un tag périmé, c'est une
     * contrainte HARD qui rate ses équipes ou en frappe d'autres — génération `COMPLETED`,
     * zéro diagnostic, planning faux.
     *
     * ⚠ On ne filtre PAS sur le changeset (« seuls name/ageMin/ageMax comptent »). La
     * dérivation est libre d'aller lire un quatrième champ demain, et le filtre serait
     * alors un bug SILENCIEUX. `syncTeamTags` est idempotent et l'édition d'une catégorie
     * est un geste rare : re-synchroniser toujours coûte moins qu'un couplage à tenir.
     */
    public function categoryUpdated(SportCategory $category): void
    {
        $this->pendingCategoryIds[] = $category->getId();
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $teams = $this->pendingTeams;
        $categoryIds = $this->pendingCategoryIds;
        // Vidés AVANT toute résolution : le flush de fin de méthode redéclenche ce
        // postFlush, qui doit ressortir immédiatement (cf. la note plus bas).
        $this->pendingTeams = [];
        $this->pendingCategoryIds = [];

        if ([] !== $categoryIds) {
            $teams = [...$teams, ...$this->teamsOfCategories($args, $categoryIds)];
        }

        if ([] === $teams) {
            return;
        }

        // Dédoublonnage : éditer une équipe ET sa catégorie dans le même flush ferait
        // sinon passer l'équipe deux fois. `syncTeamTags` supprime puis recrée — le second
        // passage supprimerait des assignations encore EN ATTENTE de persistance dans
        // l'unit of work, donc jamais écrites.
        $seen = [];
        foreach ($teams as $team) {
            $id = $team->getId();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->teamTagService->syncTeamTags($team, $team->getSeasonId());
        }

        // ⚠ CE FLUSH EST INDISPENSABLE — sans lui ce listener ne persistait RIEN.
        // `syncTeamTags` fait `remove()` des anciennes assignations puis `persist()` les
        // nouvelles, et ne flushe pas (depuis P2-13 : son seul flush restant sert au
        // backfill d'axe, conditionnel — voir `TeamTagService::getOrCreateSystemTags`).
        // En `postFlush`, le flush appelant est déjà terminé : les persist restaient donc
        // en attente indéfiniment. Constaté :
        // créer une équipe donnait 21 `team_tag` mais 0 `team_tag_assignment`, et éditer
        // une équipe SUPPRIMAIT les siennes sans les recréer. Le défaut était masqué
        // parce que `ScheduleConstraintBuilder::serializeTeam` rappelait `syncTeamTags`
        // à chaque génération, et que `GenerateScheduleHandler`, lui, flushe derrière.
        // P2-9ter ayant rendu le build en lecture seule, ce listener est devenu le SEUL
        // writer : il doit finir son travail.
        //
        // Pas de récursion : les deux files sont vidées AVANT la boucle, donc le
        // `postFlush` déclenché par ce flush-ci ressort immédiatement. Et c'est bien UN
        // flush pour tout le lot : la boucle ci-dessus n'en déclenche plus par équipe
        // (P2-13 a rendu conditionnel celui de `getOrCreateSystemTags`, qui partait N fois
        // pour N équipes importées).
        //
        // ⚠ Deux conséquences assumées. (1) Un échec d'écriture des tags remonte
        // désormais dans le `flush()` appelant et peut annuler la transaction englobante
        // — avant, il ne se produisait pas puisque rien n'était écrit ; échouer bruyamment
        // vaut mieux que des tags silencieusement absents. (2) Après un
        // `StructureRestorer::apply()`, les assignations restaurées depuis la photo d'une
        // version sont re-dérivées des champs de l'équipe restaurée : mêmes NOMS de tags,
        // ids neufs. Le payload est identique (il est trié par nom, cf. serializeTeam) et
        // les tags sont de la donnée DÉRIVÉE — re-dériver est le comportement correct.
        $args->getObjectManager()->flush();
    }

    /**
     * Les équipes rattachées aux catégories éditées.
     *
     * ⚠ **Passer par le REPOSITORY est la frontière de saison, pas un détail de style.**
     * `SportCategory` est club-scopée et SANS saison, alors que les `Team` sont copiées à
     * chaque bascule (`SeasonTransitionService`) : une catégorie a donc des équipes dans
     * N-1, N et N+1. Le `SeasonFilter` (`src/Doctrine/Filter/SeasonFilter.php`) ajoute
     * `season_id = …` à toute lecture d'entité portant cette colonne, et il est activé par
     * requête par `TenantFilterListener` — ce `findBy` ne voit donc QUE la saison courante,
     * la seule que la requête a le droit d'écrire.
     *
     * Remplacer cet appel par du SQL natif « pour éviter l'ORM » contournerait ce filtre et
     * ferait re-dériver en silence les tags d'une saison ARCHIVÉE. Le `TenantFilter` et RLS
     * bornent déjà le club ; c'est la saison qui se perdrait.
     *
     * @param list<string> $categoryIds
     *
     * @return list<Team>
     */
    private function teamsOfCategories(PostFlushEventArgs $args, array $categoryIds): array
    {
        return $args->getObjectManager()
            ->getRepository(Team::class)
            ->findBy(['sportCategoryId' => array_values(array_unique($categoryIds))]);
    }
}
