<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared by the export controllers (PDF/PNG + XLSX): read the optional export
 * scope (a single venue) from the request body and validate it belongs to the
 * schedule's club+season, so a foreign/unknown venue id can't be smuggled in.
 * One home for the tenant-scoped venue check keeps both endpoints in lockstep.
 */
trait ResolvesExportScopeTrait
{
    /** @return string|null validated venue id, or null for the all-venues scope */
    private function resolveExportVenueId(EntityManagerInterface $em, RequestStack $requestStack, Schedule $schedule): ?string
    {
        $request = $requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }
        $body = json_decode($request->getContent() ?: '{}', true);
        $venueId = \is_array($body) ? ($body['venueId'] ?? null) : null;
        if (!\is_string($venueId) || '' === $venueId) {
            return null;
        }

        $venue = $em->getRepository(Venue::class)->findOneBy([
            'id' => $venueId,
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);
        if (!$venue instanceof Venue) {
            throw new NotFoundHttpException('Venue not found.');
        }

        return $venueId;
    }
}
