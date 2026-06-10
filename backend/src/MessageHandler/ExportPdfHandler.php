<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Schedule;
use App\Message\ExportPdfMessage;
use App\Service\PdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExportPdfHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PdfGenerator $pdfGenerator,
        private HubInterface $hub,
    ) {
    }

    public function __invoke(ExportPdfMessage $message): void
    {
        $schedule = $this->findSchedule($message->getScheduleId());
        if (!$schedule instanceof Schedule) {
            return;
        }

        $schedule->setPdfExportStatus('generating');
        $this->entityManager->flush();

        try {
            $pdfUrl = $this->pdfGenerator->generate($schedule);
            $schedule->setPdfExportStatus('completed');
            $schedule->setPdfExportUrl($pdfUrl);
        } catch (\Throwable) {
            $schedule->setPdfExportStatus('failed');
            $schedule->setPdfExportUrl(null);
        }

        $this->entityManager->flush();
        $this->publishProgress($schedule);
    }

    private function findSchedule(string $scheduleId): ?Schedule
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);

        return $schedule instanceof Schedule ? $schedule : null;
    }

    private function publishProgress(Schedule $schedule): void
    {
        $topic = sprintf('club:%s:schedule:%s', $schedule->getClubId(), $schedule->getId());
        if ('club::schedule:' === $topic) {
            throw new \LogicException('Schedule Mercure topic cannot be empty.');
        }

        $this->hub->publish(new Update($topic, json_encode([
            'pdfExportStatus' => $schedule->getPdfExportStatus(),
            'pdfExportUrl' => $schedule->getPdfExportUrl(),
        ], JSON_THROW_ON_ERROR)));
    }
}
