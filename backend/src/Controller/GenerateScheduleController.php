<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Message\GenerateScheduleMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsController]
final class GenerateScheduleController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (\Throwable) {
            $schedule = null;
        }

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Schedule not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->messageBus->dispatch(
            new GenerateScheduleMessage(
                scheduleId: $schedule->getId(),
                clubId: $schedule->getClubId(),
            )
        );

        return $this->json(['message' => 'Schedule generation queued.'], Response::HTTP_ACCEPTED);
    }
}
