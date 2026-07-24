<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SchedulePlanType;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\VenuePeriodGrid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * « Reprendre la grille du planning principal » pour UN gymnase d'UNE période (#8, PR-B).
 *
 * Le geste existait déjà, mais seulement en creux : supprimer une ligne VIERGE vidait
 * puis recopiait. Sur un gymnase sans ligne — le cas courant, puisque « hériter » est le
 * défaut et ne stocke rien — il n'y avait rien à supprimer, donc rien à recopier. Le
 * contourner côté client aurait demandé deux appels (poser VIERGE, puis supprimer) : si
 * le second échoue, le gestionnaire reste avec une grille VIDÉE, exactement la perte
 * silencieuse que le round 4 de revue a servi à éliminer. D'où une action atomique.
 *
 * DESTRUCTIF et assumé : reprendre la grille emporte les créneaux du gymnase pour cette
 * période, donc leurs réservations et les verrous qu'elles avaient matérialisés. C'est
 * l'UI qui l'annonce avant (« un changement de créneau de gymnase supprime tous les
 * créneaux réservés du gymnase » — décision fondateur).
 *
 * Idempotent : rejouer la reprise redonne la même grille, jamais des doublons.
 */
#[AsController]
final class ResetVenuePeriodGridController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly VenuePeriodGrid $venuePeriodGrid,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $request = $this->requestStack->getCurrentRequest();
        $payload = $this->decode($request);
        $schedulePlanId = $payload['schedulePlanId'] ?? null;
        $venueId = $payload['venueId'] ?? null;
        if (!\is_string($schedulePlanId) || '' === $schedulePlanId || !\is_string($venueId) || '' === $venueId) {
            return $this->json(['error' => 'schedulePlanId et venueId sont requis.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $context = $this->schedulePlanProvisioner->fetchPlanContext($schedulePlanId);
        if (null === $context) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Le club vient du PLAN, jamais du corps de la requête : accepter un plan d'un
        // autre club recopierait sa grille chez nous (et inversement).
        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $context['clubId'] !== $currentClubId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        // Invariant fondateur n°1 : le planning principal n'est JAMAIS modifié par une
        // période. Visé sur le plan de SAISON, ce geste viderait la grille du club puis
        // recopierait ses propres créneaux par-dessus eux-mêmes.
        if (SchedulePlanType::SEASON === $context['type']) {
            return $this->json(['error' => 'La grille se reprend sur une période, pas sur le planning de la saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->venuePeriodGrid->resetFromSeason($schedulePlanId, $venueId);

        return $this->json(['schedulePlanId' => $schedulePlanId, 'venueId' => $venueId], Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function decode(?Request $request): array
    {
        $decoded = json_decode((string) $request?->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function resolveCurrentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        $clubId = $request?->headers->get('X-Club-Id');

        return \is_string($clubId) && '' !== $clubId ? $clubId : null;
    }
}
