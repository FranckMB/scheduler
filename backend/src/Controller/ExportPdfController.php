<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Message\ExportPdfMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsController]
final class ExportPdfController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Schedule not found.'], Response::HTTP_NOT_FOUND);
        }

        $schedule->setPdfExportStatus('pending');
        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new ExportPdfMessage(
                scheduleId: $schedule->getId(),
            )
        );

        return $this->json(['message' => 'PDF export queued.'], Response::HTTP_ACCEPTED);
    }
}
