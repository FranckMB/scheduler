<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Service\RequestIdContext;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Transporte l'id de corrélation à travers le bus asynchrone.
 *
 * - Dispatch (pas de ReceivedStamp) : tamponne l'enveloppe avec le request_id
 *   du contexte courant, sauf si un stamp est déjà présent (re-dispatch/retry).
 * - Réception (ReceivedStamp présent) : restaure le contexte depuis le stamp
 *   puis le NETTOIE en finally — patron GUC de GenerateScheduleHandler : sur un
 *   worker de longue vie, un message ne doit jamais hériter du request_id du
 *   précédent.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestIdContext $requestIdContext) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) instanceof StampInterface) {
            $stamp = $envelope->last(RequestIdStamp::class);
            if ($stamp instanceof RequestIdStamp) {
                $this->requestIdContext->set($stamp->requestId);
                $this->tagSentry($stamp->requestId);
            }

            try {
                return $stack->next()->handle($envelope, $stack);
            } finally {
                $this->requestIdContext->clear();
            }
        }

        if (!$envelope->last(RequestIdStamp::class) instanceof RequestIdStamp) {
            $requestId = $this->requestIdContext->get();
            if (null !== $requestId) {
                $envelope = $envelope->with(new RequestIdStamp($requestId));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }

    /** Inerte quand le DSN Sentry est vide (SDK non initialisé → no-op). */
    private function tagSentry(string $requestId): void
    {
        \Sentry\configureScope(static function (Scope $scope) use ($requestId): void {
            $scope->setTag('request_id', $requestId);
        });
    }
}
