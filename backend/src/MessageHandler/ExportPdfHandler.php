<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExportPdfMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExportPdfHandler
{
    public function __invoke(ExportPdfMessage $message): void
    {
        // Stub for MVP — actual PDF generation will be implemented later.
    }
}
