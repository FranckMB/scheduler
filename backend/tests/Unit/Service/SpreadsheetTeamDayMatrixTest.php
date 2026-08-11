<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Export\ScheduleExportData;
use App\Service\SpreadsheetGenerator;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * D1 — la 2ᵉ feuille « Équipes × jours » (matrice lignes = équipes, colonnes = jours,
 * cellule = gymnase + heure) est un AJOUT au classeur : la feuille Planning ne bouge pas,
 * la matrice n'apparaît qu'en multi-gymnase, et elle est construite PAR (équipe, jour) puis
 * projetée — jamais en positionnel (même piège que la feuille 1, audit D-18).
 *
 * On exerce le seam réel `appendTeamDayMatrix(Spreadsheet, ScheduleExportData)` — le collaborateur
 * `ScheduleExportDataProvider` est `final` et ne se double pas ; on lui donne donc directement une
 * `ScheduleExportData` construite à la main, puis on sérialise en XLSX et on relit le fichier pour
 * asserter sur ce que le gestionnaire ouvrira vraiment.
 */
#[Group('phase1')]
final class SpreadsheetTeamDayMatrixTest extends TestCase
{
    private const MATRIX_SHEET = 'Équipes × jours';

    /** Le contenu de la feuille Planning simulée : en-têtes + 2 lignes, figé pour prouver qu'il ne bouge pas. */
    private const PLANNING_ROWS = 2;

    public function testSecondSheetIsAddedAndPlanningStaysUntouched(): void
    {
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $book = $this->renderAndReadBack($data);

        // Deux feuilles, Planning en premier, la matrice ajoutée après.
        self::assertSame(2, $book->getSheetCount());
        self::assertSame(['Planning', self::MATRIX_SHEET], $book->getSheetNames());

        // Planning inchangée : mêmes en-têtes, même nombre de lignes qu'avant l'ajout de la matrice.
        $planning = $book->getSheet(0);
        self::assertSame($this->headers(), \array_slice($planning->toArray()[0], 0, \count($this->headers())));
        self::assertSame(1 + self::PLANNING_ROWS, $planning->getHighestDataRow(), 'la matrice n’ajoute ni ne retire aucune ligne à la feuille Planning');
    }

    public function testMatrixCellCarriesVenueAndTimeButNeverTheCoach(): void
    {
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30', 'c-1'),
                $this->slot('t-u15', 'v-b', 3, '20:00', 'c-1'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: ['c-1' => 'Jean Dupont'],
        );

        $cell = $this->matrixCell($this->renderAndReadBack($data), 'U13 F', 'Lundi');

        self::assertStringContainsString('Gymnase Municipal', $cell);
        self::assertStringContainsString('18:30', $cell);
        // Assertion négative explicite : le coach vit dans la feuille Planning, jamais ici.
        self::assertStringNotContainsString('Jean Dupont', $cell);
    }

    public function testSingleVenueOmitsTheMatrixEntirely(): void
    {
        // Tous les créneaux dans UN gymnase (réalité des placements, pas l'argument $venueId ni
        // $data->venues qui liste tout le club) : la matrice n'ajoute rien, une seule feuille.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-a', 1, '18:30'),
                $this->slot('t-u15', 'v-a', 3, '20:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $book = $this->renderAndReadBack($data);

        self::assertSame(1, $book->getSheetCount());
        self::assertSame(['Planning'], $book->getSheetNames());
    }

    public function testTwoSlotsSameDayAreBothKeptInTheCell(): void
    {
        // Une équipe qui s'entraîne 2× le mardi, deux gymnases/heures : aucun créneau ne
        // disparaît en silence, les deux vivent dans la cellule, triés par heure.
        $data = new ScheduleExportData(
            slots: [
                $this->slot('t-u13', 'v-b', 2, '20:00'),
                $this->slot('t-u13', 'v-a', 2, '18:30'),
                $this->slot('t-u15', 'v-b', 4, '19:00'),
            ],
            teamNames: ['t-u13' => 'U13 F', 't-u15' => 'U15 M'],
            teamCategories: ['t-u13' => 'U13', 't-u15' => 'U15'],
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]],
            coachNames: [],
        );

        $cell = $this->matrixCell($this->renderAndReadBack($data), 'U13 F', 'Mardi');

        self::assertStringContainsString('Gymnase Municipal', $cell);
        self::assertStringContainsString('18:30', $cell);
        self::assertStringContainsString('Salle B', $cell);
        self::assertStringContainsString('20:00', $cell);
        // Triés par heure : 18:30 avant 20:00 dans la cellule.
        self::assertLessThan(mb_strpos($cell, '20:00'), mb_strpos($cell, '18:30'));
    }

    /**
     * Équivalent feuille-2 du garde D-18 : la cellule sous « Mercredi » lit toujours le
     * mercredi de CETTE équipe, quel que soit le nombre de colonnes de jour. Ajouter un jour
     * (ici un samedi pour une autre équipe) décale les positions de colonnes mais PAS le
     * contenu, parce que la lecture se fait par NOM de jour, jamais par position.
     *
     * ⚑ Falsification : inverser deux colonnes de jours (en-tête vs cellule) ou écrire une
     * cellule en positionnel fait rougir ce test en nommant l'équipe et le jour concernés.
     */
    public function testDayColumnsAreProjectedByNameNotPosition(): void
    {
        $base = [
            $this->slot('t-u13', 'v-a', 1, '18:30'), // Lundi → Gymnase Municipal
            $this->slot('t-u13', 'v-b', 3, '20:00'), // Mercredi → Salle B
            $this->slot('t-u15', 'v-b', 5, '19:00'), // Vendredi (autre équipe)
        ];
        $withExtraDay = [...$base, $this->slot('t-u15', 'v-a', 6, '10:00')]; // + Samedi : décale les colonnes

        $venues = ['v-a' => ['name' => 'Gymnase Municipal', 'color' => null], 'v-b' => ['name' => 'Salle B', 'color' => null]];
        $names = ['t-u13' => 'U13 F', 't-u15' => 'U15 M'];
        $cats = ['t-u13' => 'U13', 't-u15' => 'U15'];

        $bookA = $this->renderAndReadBack(new ScheduleExportData($base, $names, $cats, $venues, []));
        $bookB = $this->renderAndReadBack(new ScheduleExportData($withExtraDay, $names, $cats, $venues, []));

        foreach ([$bookA, $bookB] as $book) {
            self::assertStringContainsString('Gymnase Municipal', $this->matrixCell($book, 'U13 F', 'Lundi'));
            self::assertStringContainsString('Salle B', $this->matrixCell($book, 'U13 F', 'Mercredi'));
            self::assertSame('', $this->matrixCell($book, 'U13 F', 'Vendredi'), 'U13 ne s’entraîne pas le vendredi : cellule vide, jamais le contenu d’une colonne voisine');
        }
    }

    private function slot(string $teamId, string $venueId, int $day, string $time, ?string $coachId = null): ScheduleSlotTemplate
    {
        return new ScheduleSlotTemplate()
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($time))
            ->setDurationMinutes(90)
            ->setCoachId($coachId);
    }

    /** @return list<string> */
    private function headers(): array
    {
        /** @var list<string> $headers */
        $headers = new ReflectionClass(SpreadsheetGenerator::class)->getConstant('HEADERS');

        return $headers;
    }

    /**
     * Simule la feuille Planning déjà construite (en-têtes + quelques lignes), applique le seam
     * réel `appendTeamDayMatrix`, sérialise en XLSX et relit — exactement le fichier livré.
     */
    private function renderAndReadBack(ScheduleExportData $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $planning = $spreadsheet->getActiveSheet();
        $planning->setTitle('Planning');
        foreach ($this->headers() as $i => $label) {
            $planning->setCellValue([$i + 1, 1], $label);
        }
        for ($row = 2; $row <= 1 + self::PLANNING_ROWS; ++$row) {
            $planning->setCellValue([1, $row], 'ligne existante');
        }

        // Le seam n'utilise pas la dépendance `final` du générateur ; on instancie donc ce
        // dernier sans son constructeur plutôt que de doubler un `final` (interdit par le projet).
        $generator = new ReflectionClass(SpreadsheetGenerator::class)->newInstanceWithoutConstructor();
        new ReflectionMethod(SpreadsheetGenerator::class, 'appendTeamDayMatrix')->invoke($generator, $spreadsheet, $data);

        $path = tempnam(sys_get_temp_dir(), 'xlsx_') ?: throw new RuntimeException('no temp file');
        try {
            new XlsxWriter($spreadsheet)->save($path);

            return new XlsxReader()->load($path);
        } finally {
            unlink($path);
        }
    }

    /** Cellule (équipe, jour) de la matrice, localisée PAR NOM — ligne par nom d'équipe, colonne par libellé de jour. */
    private function matrixCell(Spreadsheet $book, string $teamName, string $dayLabel): string
    {
        $sheet = $book->getSheetByName(self::MATRIX_SHEET);
        self::assertNotNull($sheet, 'la feuille matrice doit exister');
        $grid = $sheet->toArray();

        $colIndex = array_search($dayLabel, $grid[0], true);
        self::assertIsInt($colIndex, \sprintf('colonne de jour « %s » absente de l’en-tête', $dayLabel));

        foreach ($grid as $line) {
            if (($line[0] ?? null) === $teamName) {
                return (string) ($line[$colIndex] ?? '');
            }
        }
        self::fail(\sprintf('ligne de l’équipe « %s » introuvable', $teamName));
    }
}
