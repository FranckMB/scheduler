<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Club;
use App\Entity\Season;
use App\Service\PlanEntitlements;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * P1-3 PR B — le POOL DE CRÉDITS de sortie du plan Découverte (spec bridage-freemium §2/§4).
 *
 * En Découverte EFFECTIVE (offre effective Découverte, club non démo — `PlanEntitlements`
 * calcule l'effective à la lecture), CHAQUE action de SORTIE consomme 1 crédit d'un pool de
 * club partagé entre ses gestionnaires. Refus à l'épuisement (kernel.request), décompte au
 * succès (kernel.response, 2xx). Consulter et ajuster à la main ne consomment jamais rien.
 *
 * ⚠ Ce cap est le BUDGET BUSINESS, distinct du cap anti-abus P4-45 (`ClubQuotaSubscriber`) :
 * les deux vivent en kernel.request priorité 5, chacun refuse indépendamment (429 « réessayez »
 * pour le débit, 403 « passez à l'offre supérieure » pour le pool épuisé — ordre non
 * significatif, un club Découverte à sec obtient un 403 quel que soit l'état de la fenêtre de
 * débit). Ne JAMAIS fondre l'un dans l'autre — anti-abus ≠ budget.
 *
 * Priorité 5 : APRÈS `TenantFilterListener` (7), qui résout `_club_id`/`_season_id` et pose le
 * GUC — sans quoi il n'y aurait ni club ni saison à lire.
 *
 * Périmètre des routes décomptées, aligné sur `ClubQuotaSubscriber::SOLVE_ROUTES` avec UNE
 * divergence ASSUMÉE : `api_schedule_regenerate_from` n'est PAS ici. Vérifié dans le code
 * (`RegenerateFromVersionController`) : cette route RESTAURE une structure (StructureRestorer)
 * et ne dispatche AUCUN `GenerateScheduleMessage` — ce n'est pas une sortie produite, elle ne
 * brûle donc pas de crédit. Le placement de matchs et l'export PDF, eux, SONT des sorties.
 */
final class CreditBudgetSubscriber implements EventSubscriberInterface
{
    /** Les routes de SORTIE — chacune consomme 1 crédit du pool en Découverte (spec §2). */
    private const array OUTPUT_ROUTES = [
        'generate_schedule',        // POST /api/schedules/{id}/generate
        'api_schedule_regenerate',  // POST /api/schedules/{id}/regenerate
        'api_fixtures_place',       // POST /api/fixtures/place
        'export_pdf',               // POST /api/schedules/{id}/export-pdf
    ];

    public function __construct(
        private readonly PlanEntitlements $planEntitlements,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {}

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
            KernelEvents::RESPONSE => ['onKernelResponse', 5],
        ];
    }

    /** Refus AVANT le contrôleur quand le pool est épuisé — aucune sortie n'est même tentée. */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isOutputRoute($request)) {
            return;
        }

        $budget = $this->budgetFor($request);
        if (null === $budget || !$budget['restricted']) {
            return;
        }

        if ($budget['used'] >= $budget['max']) {
            throw new AccessDeniedHttpException(\sprintf('Les %d générations gratuites de votre club sont utilisées — l\'offre Essentiel les rend illimitées.', $budget['max']));
        }
    }

    /** Décompte au SUCCÈS (2xx) : un dispatch accepté (202) EST la sortie ; un 409/422 amont ne brûle rien. */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isOutputRoute($request)) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }

        $budget = $this->budgetFor($request);
        if (null === $budget || !$budget['restricted']) {
            return;
        }

        $clubId = $request->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return;
        }

        // Incrément SQL ATOMIQUE (pas de read-modify-write en PHP) : le pool est PAR CLUB,
        // partagé entre gestionnaires par construction. `club` n'a pas de colonne club_id —
        // pas de policy RLS —, l'UPDATE ciblé par id passe sur la connexion par défaut.
        $this->connection->executeStatement(
            'UPDATE club SET output_credits_used = output_credits_used + 1 WHERE id = :id',
            ['id' => $clubId],
        );
    }

    private function isOutputRoute(Request $request): bool
    {
        return \in_array((string) $request->attributes->get('_route'), self::OUTPUT_ROUTES, true);
    }

    /**
     * @return array{restricted: bool, max: int, used: int}|null null = pas de tenant/saison
     *                                                           résolu (route publique, identité non établie) : rien à borner ici, l'aval refusera
     */
    private function budgetFor(Request $request): ?array
    {
        $clubId = $request->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return null;
        }

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return null;
        }

        $seasonId = $request->attributes->get('_season_id');
        $season = \is_string($seasonId) ? $this->entityManager->getRepository(Season::class)->find($seasonId) : null;
        if (!$season instanceof Season) {
            return null;
        }

        return $this->planEntitlements->outputBudget($club, $season);
    }
}
