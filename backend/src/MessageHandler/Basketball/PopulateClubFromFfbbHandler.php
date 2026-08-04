<?php

declare(strict_types=1);

namespace App\MessageHandler\Basketball;

use App\Entity\Club;
use App\Message\Basketball\PopulateClubFromFfbbMessage;
use App\Service\Basketball\FfbbClubPopulator;
use App\Service\Basketball\FfbbTeamImporter;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Async FFBB club auto-population (lot C) + création automatique des équipes
 * engagées (P2-21 lot A). Best-effort à chaque étage : any failure is logged
 * and swallowed — a club must never be left half-created because the FFBB API
 * was down.
 *
 * GUC : la population club/comité/ligue écrit HORS RLS (pas de club_id sur ces
 * tables) — pas de contexte tenant. Les ÉQUIPES, elles, sont tenant+RLS : leur
 * import pose le GUC du club et le relâche en finally (patron
 * GenerateScheduleHandler) — sans lui, le WITH CHECK refuserait l'insert.
 */
#[AsMessageHandler]
final class PopulateClubFromFfbbHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FfbbClubPopulator $populator,
        private readonly FfbbTeamImporter $teamImporter,
        private readonly TenantConnectionContext $tenantContext,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PopulateClubFromFfbbMessage $message): void
    {
        $club = $this->em->getRepository(Club::class)->find($message->getClubId());
        if (null === $club) {
            return;
        }

        try {
            $this->populator->populate($club);
        } catch (Throwable $e) {
            $this->logger->warning('FFBB auto-population failed', [
                'clubId' => $message->getClubId(),
                'error' => $e->getMessage(),
            ]);
        }

        // Étage séparé : un échec d'équipes ne doit pas invalider la population
        // club (et réciproquement — le populate ci-dessus a déjà son catch).
        try {
            $this->tenantContext->setClubId($club->getId());
            $created = $this->teamImporter->importEngagedTeams($club);
            if ($created > 0) {
                $this->logger->info('FFBB team import: teams created at onboarding', [
                    'clubId' => $club->getId(),
                    'created' => $created,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('FFBB team import failed', [
                'clubId' => $message->getClubId(),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
