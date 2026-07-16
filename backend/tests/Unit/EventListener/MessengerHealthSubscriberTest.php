<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\MessengerHealthSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class MessengerHealthSubscriberTest extends TestCase
{
    public function testPublishesHeartbeatAndDailyRetryCount(): void
    {
        $cache = new ArrayAdapter;
        $subscriber = new MessengerHealthSubscriber($cache);

        $subscriber->onWorkerRunning(new WorkerRunningEvent($this->createStub(Worker::class), true));
        $heartbeat = $cache->getItem(MessengerHealthSubscriber::HEARTBEAT_KEY);
        self::assertTrue($heartbeat->isHit());
        self::assertIsInt($heartbeat->get());
        self::assertLessThanOrEqual(1, abs(time() - $heartbeat->get()));

        $retry = new WorkerMessageRetriedEvent(new Envelope(new stdClass), 'async');
        $subscriber->onMessageRetried($retry);
        $subscriber->onMessageRetried($retry);

        $retryCount = $cache->getItem(MessengerHealthSubscriber::RETRIES_KEY_PREFIX . gmdate('Y-m-d'));
        self::assertTrue($retryCount->isHit());
        self::assertSame(2, $retryCount->get());
    }

    public function testTelemetryFailureNeverInterruptsWorkerEvents(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new RuntimeException('Redis unavailable'));
        $subscriber = new MessengerHealthSubscriber($cache);

        $subscriber->onWorkerRunning(new WorkerRunningEvent($this->createStub(Worker::class), true));
        $subscriber->onMessageRetried(new WorkerMessageRetriedEvent(new Envelope(new stdClass), 'async'));

        self::addToAssertionCount(1);
    }
}
