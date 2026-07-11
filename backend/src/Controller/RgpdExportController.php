<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Service\RgpdExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RGPD — portabilité (art. 20), deux périmètres :
 * - GET /api/me/export : ses propres données de compte (self-only, aucun id).
 * - GET /api/club/export : le workspace du club COURANT (tenant résolu du JWT
 *   par le listener — pas d'id de chemin), réservé aux rôles management
 *   (SEC-07) : c'est le club, responsable de traitement de ses données, qui
 *   exerce la portabilité — pas n'importe quel membre.
 */
#[AsController]
final class RgpdExportController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RgpdExportService $exportService,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/me/export', name: 'api_me_export', methods: ['GET'])]
    public function exportMe(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->downloadable($this->exportService->exportUser($user), 'mes-donnees');
    }

    #[Route('/api/club/export', name: 'api_club_export', methods: ['GET'])]
    public function exportClub(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Même sémantique que les autres écritures/gates club (SEC-04/07) :
        // pas de membership actif → 404 ; membre non-management → 403.
        $membership = $this->clubUserRepository->findActiveMembership($user->getId(), $clubId);
        if (null === $membership) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $this->downloadable($this->exportService->exportClub($clubId), 'donnees-club');
    }

    /** @param array<string, mixed> $data */
    private function downloadable(array $data, string $basename): JsonResponse
    {
        $response = $this->json($data);
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s-%s.json"', $basename, date('Y-m-d')));

        return $response;
    }
}
