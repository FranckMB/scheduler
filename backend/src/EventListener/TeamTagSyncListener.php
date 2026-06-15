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

        foreach ($teams as $team) {
            $this->teamTagService->syncTeamTags($team, $team->getSeasonId());
        }
    }
}
