<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\SuperAdmin;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class AdminAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly TokenStorageInterface $tokens,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/admin/')) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        $actorId = $user instanceof SuperAdmin
            ? $user->getId()
            : $event->getRequest()->attributes->get('_admin_audit_actor_id');
        try {
            $connection = $this->registry->getConnection('admin');
            \assert($connection instanceof Connection);
            $connection->executeStatement(
                'INSERT INTO admin_audit_log (id, occurred_at, super_admin_id, action, route, status_code, details) VALUES (:id, NOW(), :actor, :action, :route, :status, :details)',
                [
                    'id' => Uuid::v4()->toRfc4122(),
                    'actor' => \is_string($actorId) ? $actorId : null,
                    'action' => 'admin.http_access',
                    'route' => $event->getRequest()->attributes->get('_route'),
                    'status' => $event->getResponse()->getStatusCode(),
                    // SA4 : un controller peut poser `_admin_audit_context` (ex. quel CLUB
                    // une action support visait) — fusionné dans details pour que même une
                    // tentative REFUSÉE trace sa cible. Jamais de contenu de requête brut
                    // ici (mot de passe/TOTP) : uniquement des attributs posés par NOS
                    // controllers. Valeurs ASSAINIES (scalaires, UTF-8 forcé, tronquées) :
                    // un segment de path %FF ferait lever JSON_THROW_ON_ERROR — et c'est
                    // l'AUDIT qui mourrait (fail-closed → session admin détruite) sur la
                    // tentative qu'on voulait justement tracer (revue SA4 round 2).
                    'details' => json_encode([
                        'method' => $event->getRequest()->getMethod(),
                        ...$this->sanitizedContext($event->getRequest()->attributes->get('_admin_audit_context')),
                    ], \JSON_THROW_ON_ERROR),
                ],
            );
        } catch (Throwable $exception) {
            // The cross-tenant surface must never operate without an audit trail.
            // Avoid logging request content: it may contain a password or TOTP code.
            $this->logger->critical('Super-admin access audit failed; request denied.', [
                'exception' => $exception,
                'route' => $event->getRequest()->attributes->get('_route'),
            ]);
            $this->tokens->setToken(null);
            if ($event->getRequest()->hasSession()) {
                $event->getRequest()->getSession()->invalidate();
            }
            $event->setResponse(new JsonResponse(['error' => 'Admin audit unavailable.'], 503));
        }
    }

    /**
     * Assainit le contexte d'audit posé par un controller : scalaires uniquement,
     * UTF-8 forcé (les octets invalides deviennent U+FFFD), tronqué à 120 caractères.
     * L'audit doit survivre à N'IMPORTE QUELLE entrée — il est fail-closed.
     *
     * @return array<string, string>
     */
    private function sanitizedContext(mixed $context): array
    {
        if (!\is_array($context)) {
            return [];
        }

        $sanitized = [];
        foreach ($context as $key => $value) {
            if (!\is_string($key) || !\is_scalar($value)) {
                continue;
            }
            $sanitized[$key] = mb_substr(mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8'), 0, 120);
        }

        return $sanitized;
    }
}
