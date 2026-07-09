<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventListener\ProdSecretGuardListener;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * A16: the prod boot guard must refuse to serve a request when a critical secret
 * still carries a committed dev value, and stay out of the way otherwise.
 */
#[Group('phase1')]
final class ProdSecretGuardListenerTest extends TestCase
{
    public function testProdBootRejectsACommittedDevSecret(): void
    {
        $listener = new ProdSecretGuardListener('prod', 'change-me-in-dev', 'a-real-jwt-passphrase', 'a-real-mercure-secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET.*development placeholder/');
        $listener->onKernelRequest($this->mainRequest());
    }

    public function testProdBootRejectsADevJwtOrMercureSecret(): void
    {
        $listener = new ProdSecretGuardListener('prod', 'a-real-app-secret', 'clubscheduler_dev_jwt_passphrase', 'a-real-mercure-secret');

        $this->expectException(RuntimeException::class);
        $listener->onKernelRequest($this->mainRequest());
    }

    public function testProdBootPassesWithRealSecrets(): void
    {
        $listener = new ProdSecretGuardListener('prod', 'Zx9-real-random-secret', 'Qw8-real-passphrase', 'Rt7-real-mercure');
        $listener->onKernelRequest($this->mainRequest());

        $this->addToAssertionCount(1); // no exception
    }

    public function testDevEnvIsNeverGuarded(): void
    {
        $listener = new ProdSecretGuardListener('dev', 'change-me-in-dev', 'clubscheduler_dev_jwt_passphrase', 'clubscheduler_dev_mercure_hs256_secret_change_me');
        $listener->onKernelRequest($this->mainRequest());

        $this->addToAssertionCount(1); // dev keeps its convenient defaults
    }

    private function mainRequest(): RequestEvent
    {
        $event = $this->createMock(RequestEvent::class);
        $event->method('isMainRequest')->willReturn(true);

        return $event;
    }
}
