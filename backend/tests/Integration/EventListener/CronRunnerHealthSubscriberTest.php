<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\EventListener\CronRunnerHealthSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Group('phase1')]
final class CronRunnerHealthSubscriberTest extends TestCase
{
    public function testWritesHeartbeatForRunDueCommand(): void
    {
        $cache = new ArrayAdapter;
        $subscriber = new CronRunnerHealthSubscriber($cache);

        $command = new Command('app:jobs:run-due');
        $event = new ConsoleCommandEvent($command, new ArrayInput([]), new BufferedOutput);

        $subscriber->onCommand($event);

        $heartbeat = $cache->getItem(CronRunnerHealthSubscriber::HEARTBEAT_KEY);
        self::assertTrue($heartbeat->isHit());
        self::assertIsInt($heartbeat->get());
        self::assertLessThanOrEqual(1, abs(time() - $heartbeat->get()));

        // TTL = 360 s (> maxAge 180 du probe) pour que l'état « down » soit atteignable.
        $metadata = $heartbeat->getMetadata();
        self::assertArrayHasKey('expiry', $metadata);
        $expectedExpiry = time() + 360;
        self::assertLessThanOrEqual(1, abs($expectedExpiry - $metadata['expiry']));
    }

    public function testIgnoresOtherCommands(): void
    {
        $cache = new ArrayAdapter;
        $subscriber = new CronRunnerHealthSubscriber($cache);

        $command = new Command('app:other:command');
        $event = new ConsoleCommandEvent($command, new ArrayInput([]), new BufferedOutput);

        $subscriber->onCommand($event);

        $heartbeat = $cache->getItem(CronRunnerHealthSubscriber::HEARTBEAT_KEY);
        self::assertFalse($heartbeat->isHit());
    }

    public function testCacheItemIsMissAfterTtlExpires(): void
    {
        $cache = new ArrayAdapter;
        $subscriber = new CronRunnerHealthSubscriber($cache);

        $command = new Command('app:jobs:run-due');
        $event = new ConsoleCommandEvent($command, new ArrayInput([]), new BufferedOutput);

        $subscriber->onCommand($event);

        $heartbeat = $cache->getItem(CronRunnerHealthSubscriber::HEARTBEAT_KEY);
        self::assertTrue($heartbeat->isHit());

        // Simulate TTL expiration by clearing the cache
        $cache->clear();

        $heartbeatAfterExpiry = $cache->getItem(CronRunnerHealthSubscriber::HEARTBEAT_KEY);
        self::assertFalse($heartbeatAfterExpiry->isHit());
    }

    public function testTelemetryFailureNeverInterruptsCommand(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new RuntimeException('Redis unavailable'));
        $subscriber = new CronRunnerHealthSubscriber($cache);

        $command = new Command('app:jobs:run-due');
        $event = new ConsoleCommandEvent($command, new ArrayInput([]), new BufferedOutput);

        $subscriber->onCommand($event);

        self::addToAssertionCount(1);
    }
}
