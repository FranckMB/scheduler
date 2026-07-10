<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Club;
use App\Message\PopulateClubFromFfbbMessage;
use App\Service\FfbbClubPopulator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Async FFBB club auto-population (lot C). Best-effort: any failure is logged
 * and swallowed — a club must never be left half-created because the FFBB API
 * was down. The club/reference tables carry no club_id (outside RLS), so no
 * tenant GUC is needed here.
 */
#[AsMessageHandler]
final class PopulateClubFromFfbbHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FfbbClubPopulator $populator,
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
    }
}
