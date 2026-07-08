<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Venue;
use App\Service\SpreadsheetGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Excel export of a schedule (flat data table). Synchronous — PhpSpreadsheet is
 * fast and needs no headless browser, so the .xlsx streams straight back as a
 * download instead of going through the async PDF/PNG worker queue.
 */
#[AsController]
final class ExportXlsxController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpreadsheetGenerator $spreadsheetGenerator,
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(string $id): Response
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        if (!$schedule instanceof Schedule) {
            return new JsonResponse(['error' => 'Schedule not found.'], Response::HTTP_NOT_FOUND);
        }

        // Explicit tenant boundary (RLS already fail-closes the find; defense-in-depth).
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $venueId = $this->readVenueId();
        if (null !== $venueId) {
            $venue = $this->entityManager->getRepository(Venue::class)->findOneBy([
                'id' => $venueId,
                'clubId' => $schedule->getClubId(),
                'seasonId' => $schedule->getSeasonId(),
            ]);
            if (!$venue instanceof Venue) {
                return new JsonResponse(['error' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
            }
        }

        $binary = $this->spreadsheetGenerator->generate($schedule, $venueId);
        $filename = \sprintf('planning-%s.xlsx', preg_replace('/[^a-zA-Z0-9_-]+/', '-', $schedule->getName()) ?: 'export');

        $response = new Response($binary, Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    private function readVenueId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }
        $body = json_decode($request->getContent() ?: '{}', true);
        $venueId = \is_array($body) ? ($body['venueId'] ?? null) : null;

        return \is_string($venueId) && '' !== $venueId ? $venueId : null;
    }
}
