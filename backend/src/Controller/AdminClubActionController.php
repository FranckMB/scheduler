<?php

declare(strict_types=1);

namespace App\Controller;

use App\AdminJob\AdminActionCatalog;
use App\AdminJob\AdminActionDefinition;
use App\AdminJob\AdminJobAlreadyRunning;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobExecutorInterface;
use App\AdminJob\AdminJobSchedule;
use App\Entity\SuperAdmin;
use App\Security\AdminSessionCsrf;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

/**
 * SA4 — actions support sur UN club, depuis le catalogue FERMÉ (AdminActionCatalog).
 * Mêmes gardes que AdminJobController (session admin + CSRF), plus l'existence du
 * club cible. La commande et ses arguments viennent exclusivement du catalogue ;
 * seul le clubId (validé) est injecté. L'exécution passe par la plomberie SA3 :
 * verrou anti-chevauchement, historique admin_job_run (avec les arguments), audit
 * SA0 (route + acteur + statut capturés par AdminAuditSubscriber).
 */
#[Route('/api/admin')]
final readonly class AdminClubActionController
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private AdminActionCatalog $catalog,
        private AdminJobExecutorInterface $executor,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ManagerRegistry $managerRegistry,
        private \App\Service\TenantConnectionContext $tenantContext,
    ) {}

    #[Route('/actions', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // Le firewall admin gate déjà l'accès ; on liste le catalogue tel quel.
        return new JsonResponse(['items' => array_map(static fn (AdminActionDefinition $a): array => [
            'key' => $a->key,
            'label' => $a->label,
            'description' => $a->description,
            'dangerous' => $a->dangerous,
        ], $this->catalog->all())]);
    }

    // PAS de requirements de route sur clubId : un requirement ferait 404 AU ROUTEUR,
    // AVANT le firewall admin — un probe non authentifié apprendrait la forme attendue
    // et la tentative ne serait ni 401 ni tracée. La forme est validée EN controller,
    // après les gardes session/CSRF (revue SA4 round 2).
    #[Route('/clubs/{clubId}/actions/{key}', methods: ['POST'])]
    public function run(string $clubId, string $key, Request $request): JsonResponse
    {
        // Contexte d'audit posé AVANT toute garde : même une tentative REFUSÉE
        // (CSRF, action/club inconnus) doit tracer QUEL club était visé — la
        // reconstruction forensique sur la surface cross-tenant l'exige (finding 4).
        // AdminAuditSubscriber fusionne cet attribut dans details.
        $request->attributes->set('_admin_audit_context', ['clubId' => $clubId, 'actionKey' => $key]);

        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }

        $action = $this->catalog->find($key);
        if (!$action instanceof AdminActionDefinition) {
            return new JsonResponse(['error' => 'Support action not found.'], 404);
        }

        // Forme UUID validée ICI (après session+CSRF, avant le SQL) : un segment
        // malformé doit être un 404 propre, jamais un 22P02 Postgres (500) — et la
        // tentative est déjà tracée par le contexte d'audit posé en tête.
        if (1 !== preg_match(self::UUID_PATTERN, $clubId)) {
            return new JsonResponse(['error' => 'Club not found.'], 404);
        }

        // Le club cible doit exister (connexion admin — la surface est cross-tenant
        // par conception). 404 AVANT toute exécution : une action sur un id fantôme
        // ne doit ni tourner ni laisser d'historique.
        $exists = (bool) $this->connection()->fetchOne('SELECT 1 FROM club WHERE id = :id', ['id' => $clubId]);
        if (!$exists) {
            return new JsonResponse(['error' => 'Club not found.'], 404);
        }

        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        // Définition ÉPHÉMÈRE : hors catalogue des jobs → jamais schedulée (et sa
        // cadence manual() lèverait si elle atteignait le scheduler). HISTORIQUE sous
        // `action:{key}` (jamais mélangé au latestRun du panneau jobs) ; VERROU sur
        // lockKey() — partagé avec le job planifié quand la commande l'est
        // (purge-seasons) : geste manuel et cron se sérialisent sans se masquer.
        // Arguments = catalogue (fixes) + le seul --club runtime (validé ci-dessus).
        $definition = new AdminJobDefinition(
            'action:' . $action->key,
            $action->label,
            $action->command,
            AdminJobSchedule::manual(),
            [...$action->arguments, '--club' => $clubId],
            manualTriggerAllowed: true,
            lockKey: $action->lockKey(),
        );

        try {
            $exitCode = $this->executor->run($definition, $admin->getId());
        } catch (AdminJobAlreadyRunning) {
            return new JsonResponse(['error' => 'Support action already running.'], 409);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Support action failed unexpectedly.'], 500);
        } finally {
            // Ceinture SA0 : une commande in-process (reset-season) scope le GUC tenant
            // sur la connexion runtime et le clear() elle-même — mais la requête ADMIN ne
            // doit JAMAIS continuer tenant-scopée, même si la commande a levé avant son
            // finally. Exception documentée dans console-superadmin.md (SA4).
            $this->tenantContext->clear();
        }

        if (Command::SUCCESS !== $exitCode) {
            return new JsonResponse(['error' => 'Support action failed.', 'key' => $key, 'exitCode' => $exitCode], 502);
        }

        return new JsonResponse(['key' => $key, 'clubId' => $clubId, 'status' => 'succeeded', 'exitCode' => $exitCode]);
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
