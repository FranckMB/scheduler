<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\RequestIdContext;
use Sentry\State\Scope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Correlation id (X-Request-Id) : lu du header, VALIDÉ, régénéré si absent ou
 * malformé, posé dans le contexte (logs + engine + Sentry) et renvoyé sur
 * TOUTE réponse.
 *
 * Priority 256 sur kernel.request — AVANT le firewall (priority 8) : l'id doit
 * couvrir aussi les réponses d'authentification (401/403) émises par le
 * firewall lui-même.
 */
final readonly class RequestIdListener implements EventSubscriberInterface
{
    public const string HEADER = 'X-Request-Id';

    public function __construct(private RequestIdContext $requestIdContext) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // 256 : avant le firewall (8), sinon un 401/403 émis par le firewall
            // sortirait sans id de corrélation.
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $incoming = $event->getRequest()->headers->get(self::HEADER);
        // Anti log-injection : un header client n'est JAMAIS ré-émis ni journalisé
        // tel quel. On n'accepte que la forme UUID (même regex que
        // TenantFilterListener::isUuid), sinon on régénère — un id maîtrisé.
        $requestId = null !== $incoming && $this->isUuid($incoming) ? $incoming : Uuid::v4()->toRfc4122();

        $this->requestIdContext->set($requestId);
        $this->tagSentry($requestId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $this->requestIdContext->get();
        if (null !== $requestId) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }

    private function isUuid(string $value): bool
    {
        // Modificateur D : sans lui, `$` matche aussi avant un \n final — un
        // « uuid\n » passerait la validation (revue sécurité du lot).
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }

    /**
     * Tag Sentry `request_id`. Inerte quand le DSN est vide (SDK non initialisé,
     * configureScope est alors un no-op). club/user ne sont PAS encore connus à
     * cette priorité (avant le firewall) : ils rejoignent le scope via
     * RequestContextProcessor sur les logs.
     */
    private function tagSentry(string $requestId): void
    {
        \Sentry\configureScope(static function (Scope $scope) use ($requestId): void {
            $scope->setTag('request_id', $requestId);
        });
    }
}
