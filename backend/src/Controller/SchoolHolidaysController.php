<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SchoolHolidayPeriod;
use App\Repository\ClubRepository;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Repository\SeasonRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cockpit feed of the club's school holidays (accueil-cockpit-temporel §4bis).
 * Reads the global reference filtered by the club's academic zone and a window
 * (from/to query, defaulting to the active season). zone null → empty items
 * (the frontend shows a "renseigner la zone" CTA).
 */
final class SchoolHolidaysController extends AbstractController
{
    public function __construct(
        private readonly SchoolHolidayPeriodRepository $holidayRepository,
        private readonly ClubRepository $clubRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/school-holidays', name: 'api_school_holidays', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $club = $this->clubRepository->find($clubId);
        $zone = $club?->getSchoolZone();
        if (null === $zone || '' === $zone) {
            return $this->json(['zone' => null, 'items' => []]);
        }

        $season = $this->seasonRepository->findActiveByClubId($clubId);

        $fromParam = $request?->query->get('from');
        $toParam = $request?->query->get('to');
        $from = $this->parseDate($fromParam) ?? $season?->getStartDate();
        $to = $this->parseDate($toParam) ?? $season?->getEndDate();
        if (null === $from || null === $to) {
            return $this->json(['error' => 'No window: pass from/to or have an active season.'], Response::HTTP_BAD_REQUEST);
        }

        $items = array_map(
            static fn (SchoolHolidayPeriod $h): array => [
                'id' => $h->getId(),
                'label' => $h->getLabel(),
                'holidayType' => $h->getHolidayType(),
                'startDate' => $h->getStartDate()->format('Y-m-d'),
                'endDate' => $h->getEndDate()->format('Y-m-d'),
                'schoolYear' => $h->getSchoolYear(),
            ],
            $this->holidayRepository->findByZoneAndWindow($zone, $from, $to),
        );

        return $this->json(['zone' => $zone, 'items' => $items]);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
