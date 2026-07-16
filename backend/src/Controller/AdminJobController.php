<?php

declare(strict_types=1);

namespace App\Controller;

use App\AdminJob\AdminJobAlreadyRunning;
use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobExecutorInterface;
use App\Entity\SuperAdmin;
use App\Security\AdminSessionCsrf;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

#[Route('/api/admin/jobs')]
final readonly class AdminJobController
{
    public function __construct(
        private AdminJobCatalog $catalog,
        private AdminJobExecutorInterface $executor,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
    ) {}

    #[Route('/{key}/run', methods: ['POST'])]
    public function run(string $key, Request $request): JsonResponse
    {
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }

        $definition = $this->catalog->find($key);
        if (!$definition instanceof AdminJobDefinition || !$definition->manualTriggerAllowed) {
            return new JsonResponse(['error' => 'Operational job not found.'], 404);
        }

        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        try {
            $exitCode = $this->executor->run($definition, $admin->getId());
        } catch (AdminJobAlreadyRunning) {
            return new JsonResponse(['error' => 'Operational job already running.'], 409);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Operational job failed unexpectedly.'], 500);
        }

        if (Command::SUCCESS !== $exitCode) {
            return new JsonResponse(['error' => 'Operational job failed.', 'key' => $key, 'exitCode' => $exitCode], 502);
        }

        return new JsonResponse(['key' => $key, 'status' => 'succeeded', 'exitCode' => $exitCode]);
    }
}
