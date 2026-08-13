<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\RequestIdMiddleware;
use App\Messenger\RequestIdStamp;
use App\Service\RequestIdContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * P5-11 — le middleware qui transporte l'id de corrélation à travers le bus.
 *
 * Dispatch : l'enveloppe repart tamponnée du request_id courant. Réception : le
 * contexte est restauré PENDANT le handling puis NETTOYÉ après (pas de fuite
 * d'un message au suivant sur le worker de longue vie).
 */
final class RequestIdMiddlewareTest extends TestCase
{
    #[Group('phase1')]
    public function testDispatchStampsTheCurrentRequestId(): void
    {
        $context = new RequestIdContext;
        $context->set('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
        $middleware = new RequestIdMiddleware($context);

        $result = $this->drive($middleware, new Envelope(new stdClass), null);

        $stamp = $result->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $stamp->requestId);
    }

    #[Group('phase1')]
    public function testReceptionRestoresThenClearsTheContext(): void
    {
        $context = new RequestIdContext;
        $middleware = new RequestIdMiddleware($context);

        $seenDuringHandling = null;
        $envelope = new Envelope(new stdClass, [
            new ReceivedStamp('async'),
            new RequestIdStamp('12345678-90ab-4cde-8f01-234567890abc'),
        ]);

        $this->drive($middleware, $envelope, function () use ($context, &$seenDuringHandling): void {
            $seenDuringHandling = $context->get();
        });

        // Restauré PENDANT le handling…
        self::assertSame('12345678-90ab-4cde-8f01-234567890abc', $seenDuringHandling);
        // …et nettoyé APRÈS (finally) : aucune fuite vers le message suivant.
        self::assertNull($context->get());
    }

    /**
     * Appelle DIRECTEMENT le middleware avec un stack dont le `next()` rend un
     * terminal qui exécute $onHandle (si présent) et renvoie l'enveloppe telle
     * quelle — patron unitaire idiomatique d'un middleware Messenger (un
     * StackMiddleware nourri d'un tableau démarre son générateur et sauterait le
     * premier maillon).
     */
    private function drive(RequestIdMiddleware $middleware, Envelope $envelope, ?callable $onHandle): Envelope
    {
        $terminal = new class($onHandle) implements MiddlewareInterface {
            /** @param callable():void|null $onHandle */
            public function __construct(private $onHandle) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                if (null !== $this->onHandle) {
                    ($this->onHandle)();
                }

                return $envelope;
            }
        };

        $stack = $this->createMock(StackInterface::class);
        $stack->method('next')->willReturn($terminal);

        return $middleware->handle($envelope, $stack);
    }
}
