<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PublicHoliday;
use App\Repository\ClubRepository;
use App\Repository\PublicHolidayRepository;
use App\Repository\SeasonRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cockpit feed of the club's public holidays (jours fériés). Returns the NATIONAL
 * fériés UNION the club's territory-specific ones (Club.schoolZone), within a
 * window (from/to query, defaulting to the active season). Unlike school holidays,
 * a null zone still returns the NATIONAL fériés (they apply to every club).
 * Display-only — never consumed by the solver.
 */
final class PublicHolidaysController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly PublicHolidayRepository $holidayRepository,
        private readonly ClubRepository $clubRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/public-holidays', name: 'api_public_holidays', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $request = $this->requestStack->getCurrentRequest();
        $club = $this->clubRepository->find($clubId);
        $zone = $club?->getSchoolZone();

        $season = $this->seasonRepository->findActiveByClubId($clubId);

        // A provided-but-invalid from/to is a client error, not a silent
        // fallback to the season window.
        $from = $this->parseDate($request?->query->get('from')) ?? $season?->getStartDate();
        $to = $this->parseDate($request?->query->get('to')) ?? $season?->getEndDate();
        if (false === $from || false === $to) {
            return $this->json(['error' => 'Query params from/to must be valid dates (YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }
        if (null === $from || null === $to) {
            return $this->json(['error' => 'No window: pass from/to or have an active season.'], Response::HTTP_BAD_REQUEST);
        }

        $items = array_map(
            static fn (PublicHoliday $h): array => [
                'id' => $h->getId(),
                'date' => $h->getDate()->format('Y-m-d'),
                'label' => $h->getLabel(),
                'national' => $h->isNational(),
            ],
            $this->holidayRepository->findNationalAndZoneInWindow($zone, $from, $to),
        );

        return $this->json(['zone' => $zone, 'items' => $items]);
    }

    /**
     * null = param absent (use the season default); false = provided but invalid
     * (400); DateTimeImmutable = a real calendar date. Strict: rejects both
     * malformed strings and rollovers like 2026-02-30 / 2026-13-01.
     */
    private function parseDate(mixed $value): DateTimeImmutable|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }

        return $date;
    }
}
