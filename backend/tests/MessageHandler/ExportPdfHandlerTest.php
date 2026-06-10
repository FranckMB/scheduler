<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Schedule;
use App\Message\ExportPdfMessage;
use App\MessageHandler\ExportPdfHandler;
use App\Service\PdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class ExportPdfHandlerTest extends TestCase
{
    private const SCHEDULE_ID = '11111111-1111-4111-8111-111111111111';
    private const CLUB_ID = '22222222-2222-4222-8222-222222222222';
    private const SEASON_ID = '33333333-3333-4333-8333-333333333333';

    /** @group phase1 */
    public function testSuccessfulExportSetsCompletedAndUrlAndPublishesUpdate(): void
    {
        $schedule = $this->schedule();
        $publishedUpdates = [];
        $entityManager = $this->entityManager($schedule);
        $pdfGenerator = $this->pdfGenerator('/exports/schedule-'.self::SCHEDULE_ID.'.pdf');

        $handler = new ExportPdfHandler(
            $entityManager,
            $pdfGenerator,
            $this->hub($publishedUpdates),
        );

        $handler(new ExportPdfMessage(self::SCHEDULE_ID));

        self::assertSame('completed', $schedule->getPdfExportStatus());
        self::assertSame('/exports/schedule-'.self::SCHEDULE_ID.'.pdf', $schedule->getPdfExportUrl());
        self::assertCount(1, $publishedUpdates);
        self::assertSame(sprintf('club:%s:schedule:%s', self::CLUB_ID, self::SCHEDULE_ID), $publishedUpdates[0]->getTopics()[0]);
        self::assertSame([
            'pdfExportStatus' => 'completed',
            'pdfExportUrl' => '/exports/schedule-'.self::SCHEDULE_ID.'.pdf',
        ], json_decode($publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @group phase1 */
    public function testFailedExportSetsFailedAndNullUrlAndPublishesUpdate(): void
    {
        $schedule = $this->schedule();
        $publishedUpdates = [];
        $entityManager = $this->entityManager($schedule);
        $pdfGenerator = $this->pdfGenerator(null);

        $handler = new ExportPdfHandler(
            $entityManager,
            $pdfGenerator,
            $this->hub($publishedUpdates),
        );

        $handler(new ExportPdfMessage(self::SCHEDULE_ID));

        self::assertSame('failed', $schedule->getPdfExportStatus());
        self::assertNull($schedule->getPdfExportUrl());
        self::assertCount(1, $publishedUpdates);
        self::assertSame([
            'pdfExportStatus' => 'failed',
            'pdfExportUrl' => null,
        ], json_decode($publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function schedule(): Schedule
    {
        return (new Schedule())
            ->setId(self::SCHEDULE_ID)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Test Schedule')
            ->setStatus('done');
    }

    private function entityManager(Schedule $schedule): EntityManagerInterface&MockObject
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($schedule);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->willReturnCallback(static fn (string $className): EntityRepository => $repository);
        $entityManager->expects(self::exactly(2))
            ->method('flush');

        return $entityManager;
    }

    private function pdfGenerator(?string $returnUrl): PdfGenerator&MockObject
    {
        $generator = $this->createMock(PdfGenerator::class);
        if (null === $returnUrl) {
            $generator->expects(self::once())
                ->method('generate')
                ->willThrowException(new \RuntimeException('PDF generation failed.'));
        } else {
            $generator->expects(self::once())
                ->method('generate')
                ->willReturn($returnUrl);
        }

        return $generator;
    }

    /** @param list<Update> $publishedUpdates */
    private function hub(array &$publishedUpdates): HubInterface&MockObject
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update) use (&$publishedUpdates): bool {
                $publishedUpdates[] = $update;

                return true;
            }))
            ->willReturn('update-id');

        return $hub;
    }
}
