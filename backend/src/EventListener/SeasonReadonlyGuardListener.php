<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\SeasonScopedWriteInterface;
use App\Service\SeasonAccessGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses (409) a write to an archived season on the custom controllers marked
 * SeasonScopedWriteInterface — the counterpart of the SeasonAccessGuard call
 * in AbstractStateProcessor, which only covers API Platform mutations. Runs on
 * kernel.controller (after TenantFilterListener has stamped _season_readonly).
 */
final class SeasonReadonlyGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SeasonAccessGuard $seasonAccessGuard,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        // Symfony passes invokable controllers as [object, '__invoke'] or the object itself.
        $instance = \is_array($controller) ? $controller[0] : $controller;

        if ($instance instanceof SeasonScopedWriteInterface) {
            $this->seasonAccessGuard->assertWritable($event->getRequest());
        }
    }
}
