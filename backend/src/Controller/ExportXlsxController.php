<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Service\SpreadsheetGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    use ResolvesExportScopeTrait;

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

        $venueId = $this->resolveExportVenueId($this->entityManager, $this->requestStack, $schedule);

        $binary = $this->spreadsheetGenerator->generate($schedule, $venueId);

        $response = new Response($binary, Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // makeDisposition emits both an ASCII fallback and a RFC 5987 filename*,
        // so an accented schedule name survives instead of collapsing to dashes.
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('planning-%s.xlsx', $schedule->getName()),
            \sprintf('planning-%s.xlsx', preg_replace('/[^a-zA-Z0-9_-]+/', '-', $schedule->getName()) ?: 'export'),
        ));

        return $response;
    }
}
