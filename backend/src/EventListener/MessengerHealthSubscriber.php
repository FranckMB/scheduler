<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Throwable;

/** Publishes lightweight worker liveness and retry telemetry in the shared Redis cache. */
final class MessengerHealthSubscriber implements EventSubscriberInterface
{
    public const HEARTBEAT_KEY = 'admin_monitoring.messenger.heartbeat';
    public const RETRIES_KEY_PREFIX = 'admin_monitoring.messenger.retries.';

    private const HEARTBEAT_INTERVAL_SECONDS = 10;

    private int $lastHeartbeatWrite = 0;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerMessageRetriedEvent::class => 'onMessageRetried',
        ];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $now = time();
        if ($now - $this->lastHeartbeatWrite < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        try {
            $heartbeat = $this->cache->getItem(self::HEARTBEAT_KEY);
            $heartbeat->set($now)->expiresAfter(60);
            $this->cache->save($heartbeat);
            $this->lastHeartbeatWrite = $now;
        } catch (Throwable) {
            // Telemetry is best-effort and must never interrupt the worker loop.
        }
    }

    public function onMessageRetried(WorkerMessageRetriedEvent $event): void
    {
        try {
            $retryCount = $this->cache->getItem(self::RETRIES_KEY_PREFIX . gmdate('Y-m-d'));
            $current = $retryCount->isHit() && \is_int($retryCount->get()) ? $retryCount->get() : 0;
            $retryCount->set($current + 1)->expiresAfter(172800);
            $this->cache->save($retryCount);
        } catch (Throwable) {
            // A telemetry failure cannot alter Messenger retry semantics.
        }
    }
}
