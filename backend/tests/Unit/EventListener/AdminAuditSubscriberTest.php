<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\AdminAuditSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class AdminAuditSubscriberTest extends TestCase
{
    public function testAuditFailureDeniesRequestAndInvalidatesAdminSession(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new RuntimeException('database unavailable'));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnection')->with('admin')->willReturn($connection);
        $tokens = $this->createMock(TokenStorageInterface::class);
        $tokens->expects(self::once())->method('setToken')->with(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        $request = Request::create('/api/admin/auth/me');
        $session = new Session(new MockArraySessionStorage);
        $session->set('proof', 'present');
        $request->setSession($session);
        $request->attributes->set('_route', 'app_adminauth_me');
        $event = new ResponseEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('sensitive admin data'),
        );

        new AdminAuditSubscriber($registry, $tokens, $logger)->onResponse($event);

        self::assertSame(503, $event->getResponse()->getStatusCode());
        self::assertSame([], $session->all());
    }
}
