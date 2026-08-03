<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Team;
use App\Service\TeamTagService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postPersist, entity: Team::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Team::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TeamTagSyncListener
{
    /** @var list<Team> */
    private array $pendingTeams = [];

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

    public function postFlush(PostFlushEventArgs $args): void
    {
        $teams = $this->pendingTeams;
        $this->pendingTeams = [];
        if ([] === $teams) {
            return;
        }

        foreach ($teams as $team) {
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
        // Pas de récursion : `$pendingTeams` est vidé AVANT la boucle, donc le
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
}
