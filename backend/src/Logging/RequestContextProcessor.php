<?php

declare(strict_types=1);

namespace App\Logging;

use App\Entity\User;
use App\Service\RequestIdContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Enrichit chaque ligne de log avec le contexte de corrélation : `request_id`
 * (RequestIdContext), `club_id` (attribut `_club_id` posé par
 * TenantFilterListener) et `user_id` (jeton de sécurité).
 *
 * ⚠ IDS SEULEMENT — jamais d'email ni de nom (rgpd.md : « ids uniquement,
 * jamais de PII »). Autoconfiguré : MonologBundle taggue `monolog.processor`
 * tout service implémentant ProcessorInterface → s'applique à tous les handlers.
 */
final readonly class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestIdContext $requestIdContext,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->requestIdContext->get();
        if (null !== $requestId) {
            $record->extra['request_id'] = $requestId;
        }

        $clubId = $this->currentClubId();
        if (null !== $clubId) {
            $record->extra['club_id'] = $clubId;
        }

        $userId = $this->currentUserId();
        if (null !== $userId) {
            $record->extra['user_id'] = $userId;
        }

        return $record;
    }

    private function currentClubId(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $clubId = $request->attributes->get('_club_id');

        return \is_string($clubId) && '' !== $clubId ? $clubId : null;
    }

    private function currentUserId(): ?string
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
