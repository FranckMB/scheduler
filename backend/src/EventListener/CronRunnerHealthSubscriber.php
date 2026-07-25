<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/** Publishes a heartbeat at the start of every app:jobs:run-due execution so a stale key signals a crash. */
final class CronRunnerHealthSubscriber implements EventSubscriberInterface
{
    public const HEARTBEAT_KEY = 'admin_monitoring.cron_runner.heartbeat';

    // TTL > maxAge du probe (180 s) : la clé doit survivre AU-DELÀ de maxAge pour que l'état
    // « down » soit atteignable (TTL == maxAge ferait sauter up→unknown directement).
    private const HEARTBEAT_TTL_SECONDS = 360;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (null === $command || 'app:jobs:run-due' !== $command->getName()) {
            return;
        }

        try {
            $heartbeat = $this->cache->getItem(self::HEARTBEAT_KEY);
            $heartbeat->set(time())->expiresAfter(self::HEARTBEAT_TTL_SECONDS);
            $this->cache->save($heartbeat);
        } catch (Throwable) {
            // Telemetry is best-effort and must never interrupt the cron loop.
        }
    }
}
