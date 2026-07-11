<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\AccountErasureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RGPD — droit à l'effacement, self-service (DELETE /api/me).
 *
 * Self-only : la cible est TOUJOURS l'utilisateur du JWT — aucun id en entrée,
 * donc aucun IDOR possible. Confirmation forte : le body doit re-saisir l'email
 * exact du compte (même patron de friction que la suppression d'entités côté
 * UI). L'anonymisation est immédiate et irréversible ; la purge du club
 * orphelin part avec un délai de grâce de 30 j (AccountErasureService).
 */
#[AsController]
final class DeleteAccountController extends AbstractController
{
    public function __construct(
        private readonly AccountErasureService $accountErasureService,
    ) {}

    #[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $confirm = \is_array($data) && \is_string($data['email'] ?? null) ? strtolower(trim($data['email'])) : '';
        if ($confirm !== $user->getEmail()) {
            return $this->json(['error' => 'Confirmez en saisissant l\'adresse e-mail exacte du compte.'], 400);
        }

        $scheduledClubs = $this->accountErasureService->erase($user);

        return $this->json([
            'message' => 'Compte anonymisé. Vos données personnelles ont été effacées.',
            'clubPurgeScheduled' => [] !== $scheduledClubs,
            // Le front affiche la conséquence : sans autre gestionnaire, les
            // données du club seront supprimées à l'issue du délai de grâce.
            'gracePeriodDays' => 30,
        ]);
    }
}
