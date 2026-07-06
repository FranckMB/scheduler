<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LeagueMatchWindow;
use App\Repository\ClubRepository;
use App\Repository\LeagueMatchWindowRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Match-window catalog inherited by the club (spec gestion-matchs §6bis): the
 * federation-imposed HARD envelope of allowed kickoff windows for the club's
 * derived league, falling back to the federation default (AURA) when that
 * league is not catalogued yet. GLOBAL reference — read-only, display feed for
 * the future placement grid / conflict radar.
 */
final class LeagueMatchWindowsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly LeagueMatchWindowRepository $windowRepository,
        private readonly ClubRepository $clubRepository,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/league-match-windows', name: 'api_league_match_windows', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $league = $this->clubRepository->find($clubId)?->getLeague();
        $effectiveLeague = $this->windowRepository->effectiveLeague($league);

        $items = array_map(
            static fn (LeagueMatchWindow $w): array => [
                'id' => $w->getId(),
                'league' => $w->getLeague(),
                'category' => $w->getCategory(),
                'level' => $w->getLevel(),
                'gender' => $w->getGender(),
                'dayOfWeek' => $w->getDayOfWeek(),
                'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                'kickoffMax' => $w->getKickoffMax()->format('H:i'),
            ],
            $this->windowRepository->findEnvelopeForLeague($league),
        );

        return $this->json(['league' => $effectiveLeague, 'items' => $items]);
    }
}
