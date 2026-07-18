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
    public function __construct(
        private AdminActionCatalog $catalog,
        private AdminJobExecutorInterface $executor,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ManagerRegistry $managerRegistry,
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

    #[Route('/clubs/{clubId}/actions/{key}', methods: ['POST'])]
    public function run(string $clubId, string $key, Request $request): JsonResponse
    {
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

        // Le club cible doit exister (connexion admin — la surface est cross-tenant
        // par conception). 404 AVANT toute exécution : une action sur un id fantôme
        // ne doit ni tourner ni laisser d'historique.
        $exists = (bool) $this->connection()->fetchOne('SELECT 1 FROM club WHERE id = :id', ['id' => $clubId]);
        if (!$exists) {
            return new JsonResponse(['error' => 'Club not found.'], 404);
        }

        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        // Définition ÉPHÉMÈRE : hors catalogue des jobs → jamais schedulée (et sa
        // cadence manual() lèverait si elle atteignait le scheduler). Le verrou et
        // l'historique sont par action (clé stable), le club tracé via arguments.
        $definition = new AdminJobDefinition(
            'action:' . $action->key,
            $action->label,
            $action->command,
            AdminJobSchedule::manual(),
            ['--club' => $clubId],
            manualTriggerAllowed: true,
        );

        try {
            $exitCode = $this->executor->run($definition, $admin->getId());
        } catch (AdminJobAlreadyRunning) {
            return new JsonResponse(['error' => 'Support action already running.'], 409);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Support action failed unexpectedly.'], 500);
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
