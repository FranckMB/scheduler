<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export;

use App\Export\ExportView;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * P3-20 — la vue demandée pour l'image atteint un NOM DE FICHIER : elle est en liste
 * blanche, et une valeur inconnue est refusée au lieu de retomber en silence sur la grille
 * (le gestionnaire recevrait alors l'inverse de ce qu'il a demandé, sans un mot).
 */
#[Group('phase1')]
final class ExportViewTest extends TestCase
{
    public function testAbsentOrEmptyKeepsTheHistoricalGridView(): void
    {
        self::assertSame(ExportView::GRID, ExportView::fromRequestBody([]));
        self::assertSame(ExportView::GRID, ExportView::fromRequestBody(['venueId' => 'v-1']));
        self::assertSame(ExportView::GRID, ExportView::fromRequestBody(['view' => '']));
        // Corps illisible (non tableau) : le défaut, jamais une erreur — l'export doit partir.
        self::assertSame(ExportView::GRID, ExportView::fromRequestBody(null));
    }

    public function testClubViewIsAccepted(): void
    {
        self::assertSame(ExportView::CLUB, ExportView::fromRequestBody(['view' => 'club']));
    }

    public function testUnknownViewIsRefusedRatherThanSilentlyDowngraded(): void
    {
        $this->expectException(BadRequestHttpException::class);
        ExportView::fromRequestBody(['view' => 'matrix']);
    }
}
