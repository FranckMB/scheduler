<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\SeasonStatus;
use App\Service\TeamTagService;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Re-dérive les tags système de toutes les équipes d'un club (ou de tous les clubs).
 *
 * `TeamTagSyncListener` ne pose les tags qu'à l'ÉCRITURE d'une équipe ou d'une catégorie :
 * quand le catalogue de tags système gagne de nouveaux membres (ADULTE/SENIOR/COMPETITION,
 * Volet A « tags »), un club EXISTANT dont rien n'a bougé garde ses anciennes assignations et
 * les nouveaux tags ne visent personne. Cette commande rejoue la dérivation — `syncTeamTags`,
 * FOYER UNIQUE de la règle : jamais un backfill SQL qui recopierait la logique et divergerait.
 *
 * Idempotente (syncTeamTags purge puis recrée) et bornée aux saisons vivantes (ACTIVE/DRAFT) :
 * une saison archivée n'est pas re-dérivée. Un club fautif est isolé et n'arrête pas les autres.
 *
 * ⚠ Chaque club est scopé par `TenantConnectionContext::setClubId` (GUC `app.club_id`) pour que
 * la RLS laisse lire/écrire ses lignes, et le contexte est relâché en `finally`.
 */
#[AsCommand(
    name: 'app:team-tags:resync',
    description: 'Re-derive system team tags for every team of a club (or all clubs) via TeamTagService.',
)]
final class ResyncTeamTagsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamTagService $teamTagService,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Limit to one club id (default: all clubs).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clubFilter = $input->getOption('club');
        $repository = $this->entityManager->getRepository(Club::class);
        $clubs = \is_string($clubFilter) && '' !== $clubFilter
            ? array_filter([$repository->find($clubFilter)])
            : $repository->findAll();

        if ([] === $clubs) {
            $io->error(\is_string($clubFilter) ? \sprintf('Club %s not found.', $clubFilter) : 'No club found.');

            return Command::FAILURE;
        }

        $this->entityManager->clear();

        $failure = false;
        $teamsTotal = 0;
        foreach ($clubs as $club) {
            $clubId = $club->getId();
            try {
                $teamsTotal += $this->resyncClub($clubId);
            } catch (Throwable $e) {
                $failure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $clubId, $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d team(s) re-tagged across %d club(s).', $teamsTotal, \count($clubs)));

        return $failure ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return int the number of teams re-tagged for this club */
    private function resyncClub(string $clubId): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        // Saisons VIVANTES seulement (une archivée reste figée). Le tag_id des assignations
        // ne change pas d'une saison à l'autre : la re-dérivation est per (équipe, saison).
        $seasons = array_filter(
            $this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId]),
            static fn (Season $s): bool => \in_array($s->getStatus(), [SeasonStatus::ACTIVE, SeasonStatus::DRAFT], true),
        );

        $count = 0;
        foreach ($seasons as $season) {
            $teams = $this->entityManager->getRepository(Team::class)->findBy([
                'clubId' => $clubId,
                'seasonId' => $season->getId(),
            ]);
            foreach ($teams as $team) {
                $this->teamTagService->syncTeamTags($team, $season->getId());
                ++$count;
            }
        }

        // syncTeamTags remove()/persist() sans flusher (P2-13) : c'est l'appelant qui écrit.
        $this->entityManager->flush();

        return $count;
    }
}
