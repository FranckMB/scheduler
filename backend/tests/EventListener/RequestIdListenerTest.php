<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\RequestIdListener;
use App\Service\RequestIdContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * P5-11 — l'id de corrélation (X-Request-Id).
 *
 * Trois garanties : (1) TOUTE réponse porte le header ; (2) un id valide reçu
 * est ré-émis tel quel ; (3) un id MALFORMÉ est régénéré et JAMAIS renvoyé tel
 * quel (anti log-injection — un header client ne devient jamais un id maîtrisé
 * sans passer la forme UUID).
 */
final class RequestIdListenerTest extends TestCase
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    #[Group('phase1')]
    public function testResponseAlwaysCarriesTheHeader(): void
    {
        $context = new RequestIdContext;
        $listener = new RequestIdListener($context);

        $request = new Request;
        $listener->onKernelRequest($this->requestEvent($request));

        $response = new Response;
        $listener->onKernelResponse($this->responseEvent($request, $response));

        $header = $response->headers->get('X-Request-Id');
        self::assertNotNull($header);
        self::assertMatchesRegularExpression(self::UUID_RE, $header);
        self::assertSame($context->get(), $header);
    }

    #[Group('phase1')]
    public function testValidIncomingIdIsEchoedUnchanged(): void
    {
        $context = new RequestIdContext;
        $listener = new RequestIdListener($context);

        $incoming = '11111111-2222-4333-8444-555555555555';
        $request = new Request;
        $request->headers->set('X-Request-Id', $incoming);
        $listener->onKernelRequest($this->requestEvent($request));

        self::assertSame($incoming, $context->get());

        $response = new Response;
        $listener->onKernelResponse($this->responseEvent($request, $response));
        self::assertSame($incoming, $response->headers->get('X-Request-Id'));
    }

    #[Group('phase1')]
    public function testMalformedIncomingIdIsRegeneratedNeverEchoed(): void
    {
        $context = new RequestIdContext;
        $listener = new RequestIdListener($context);

        $malformed = "garbage\r\nX-Injected: evil";
        $request = new Request;
        $request->headers->set('X-Request-Id', $malformed);
        $listener->onKernelRequest($this->requestEvent($request));

        // Régénéré : jamais l'écho de la valeur cliente malformée.
        self::assertNotSame($malformed, $context->get());
        self::assertMatchesRegularExpression(self::UUID_RE, (string) $context->get());

        $response = new Response;
        $listener->onKernelResponse($this->responseEvent($request, $response));
        self::assertNotSame($malformed, $response->headers->get('X-Request-Id'));
        self::assertMatchesRegularExpression(self::UUID_RE, (string) $response->headers->get('X-Request-Id'));
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
