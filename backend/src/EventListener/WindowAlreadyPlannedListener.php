<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\WindowAlreadyPlannedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ADR-0002 inv. 4 (P2-38 PR2) — rend le 409 STRUCTURÉ de la garde « une seule planification
 * par fenêtre ».
 *
 * Un State Processor ne peut pas émettre un corps sur-mesure : une exception qu'il lève est
 * rendue par API Platform en `{detail, status}` seuls (aucun code machine, aucun id). Cette
 * garde-là exige la MÊME forme que le `overlays_exist` déjà en vigueur (code machine + de quoi
 * naviguer côté front). On la compose donc ici, sur `kernel.exception`, à partir de la seule
 * {@see WindowAlreadyPlannedException}.
 *
 * Priority 256 : AVANT Sentry (128) et l'ExceptionListener d'API Platform (-96). On pose la
 * réponse puis on STOPPE la propagation — le 409 est un résultat métier attendu, pas une erreur
 * à journaliser ni à remonter à Sentry. Toute autre exception traverse ce listener sans effet.
 */
final class WindowAlreadyPlannedListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 256]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof WindowAlreadyPlannedException) {
            return;
        }

        // Même patron que `overlays_exist` (ValidateScheduleController) : `code` machine +
        // `error` humain, plus l'`entryId` du plan en place pour que le front y mène.
        $event->setResponse(new JsonResponse([
            'code' => 'window_already_planned',
            'error' => $exception->getMessage(),
            'entryId' => $exception->conflictingEntryId,
        ], Response::HTTP_CONFLICT));
        $event->stopPropagation();
    }
}
