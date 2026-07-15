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
                    'details' => json_encode(['method' => $event->getRequest()->getMethod()], \JSON_THROW_ON_ERROR),
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
}
