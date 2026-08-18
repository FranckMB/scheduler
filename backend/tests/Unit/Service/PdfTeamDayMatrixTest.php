<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Export\ScheduleExportData;
use App\Service\PdfGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * D2 — the PDF export gains a second section : a "team × day" matrix (rows = teams,
 * columns = the days actually used, cell = gym + time, NO coach), grouped by priority
 * tier S→A→B→C→D. It is a MULTI-page, back-compatible add : the manager grid (page 1)
 * and the single-section worker payload do not change ; the matrix only appears on a
 * genuinely multi-venue export, and each rank group is kept whole in pagination.
 *
 * The pure builders are exercised through reflection with a hand-built ScheduleExportData
 * (its collaborators are `final` and not doubled), exactly like the XLSX sibling test.
 * Chromium's real pagination is not — and cannot be — asserted here ; it is verified by
 * producing and eyeballing a real PDF (see the D2 report).
 */
#[Group('phase1')]
final class PdfTeamDayMatrixTest extends TestCase
{
    public function testMatrixAppearsOnlyWhenPlacementsSpanTwoVenues(): void
    {
        // Two venues among the PLACEMENTS → matrix. The trigger reads the reality of the
        // placements, never the $data->venues map (which lists every club venue).
        self::assertTrue($this->hasMatrix($this->data([
            $this->slot('t-a', 'v-a', 1, '18:30'),
            $this->slot('t-b', 'v-b', 3, '20:00'),
        ])));

        // Same $venues map, but every placement in ONE venue → no matrix (mono-gymnase rule).
        self::assertFalse($this->hasMatrix($this->data([
            $this->slot('t-a', 'v-a', 1, '18:30'),
            $this->slot('t-b', 'v-a', 3, '20:00'),
        ])), 'un seul gymnase distinct parmi les placements : pas de matrice, même si le club a d’autres gymnases');
    }

    public function testMatrixCellCarriesVenueAndTimeButNeverTheCoach(): void
    {
        $html = $this->buildMatrix($this->data(
            [
                $this->slot('t-a', 'v-a', 1, '18:30', 'c-1'),
                $this->slot('t-b', 'v-b', 3, '20:00', 'c-1'),
            ],
            coachNames: ['c-1' => 'Jean Dupont'],
        ));

        self::assertStringContainsString('Gymnase Municipal · 18:30', $html);
        // Assertion négative explicite : le coach vit dans la grille (page 1), jamais dans la matrice.
        self::assertStringNotContainsString('Jean Dupont', $html);
    }

    public function testTeamWithoutAnySessionKeepsAnEmptyRow(): void
    {
        // A team with no session is the hole a manager must see : its row stays, cells empty.
        $html = $this->buildMatrix($this->data(
            [
                $this->slot('t-a', 'v-a', 1, '18:30'),
                $this->slot('t-b', 'v-b', 3, '20:00'),
            ],
            teamNames: ['t-a' => 'U13 F', 't-b' => 'U15 M', 't-ghost' => 'U17 M'],
            teamCategories: ['t-a' => 'U13', 't-b' => 'U15', 't-ghost' => 'U17'],
            teamRanks: [
                't-a' => $this->rank('A', 'Régional+', 2, 0),
                't-b' => $this->rank('A', 'Régional+', 2, 1),
                't-ghost' => $this->rank('B', 'Régional', 3, 0),
            ],
        ));

        // La ligne de l'équipe sans séance existe (son nom apparaît comme cellule d'équipe)...
        self::assertStringContainsString('U17 M', $html, 'une équipe sans séance garde sa ligne dans la matrice');
        // ...et elle ne porte aucun créneau (aucun « · » de cellule sur sa ligne).
        $ghostRow = $this->rowContaining($html, 'U17 M');
        self::assertStringNotContainsString(' · ', $ghostRow, 'la ligne d’une équipe sans séance ne porte aucun créneau');
    }

    /**
     * P4-106 (décision fondateur 2026-08-18) : le rang ORDONNE les groupes mais ne s'AFFICHE
     * PLUS. Un export part au gymnase et aux familles — la priorisation interne des équipes
     * n'y a pas sa place. Ce test épingle les deux moitiés : l'ordre S→A→B→C→D tient (par la
     * position des NOMS d'équipes), et aucune étiquette de rang ne subsiste dans le document.
     */
    public function testRankOrdersTheGroupsWithoutPrintingAnyLabel(): void
    {
        // Teams handed in scrambled tier order ; the matrix must group them S→A→B→C→D.
        $html = $this->buildMatrix($this->data(
            [
                $this->slot('t-d', 'v-a', 1, '18:00'),
                $this->slot('t-s', 'v-b', 1, '19:00'),
                $this->slot('t-b', 'v-a', 2, '18:00'),
                $this->slot('t-a', 'v-b', 2, '19:00'),
                $this->slot('t-c', 'v-a', 3, '18:00'),
            ],
            teamNames: ['t-d' => 'Loisir 1', 't-s' => 'Elite 1', 't-b' => 'Reg 1', 't-a' => 'RegPlus 1', 't-c' => 'Dep 1'],
            teamCategories: [],
            teamRanks: [
                't-d' => $this->rank('D', 'Loisir', 5, 0),
                't-s' => $this->rank('S', 'Elite', 1, 0),
                't-b' => $this->rank('B', 'Régional', 3, 0),
                't-a' => $this->rank('A', 'Régional+', 2, 0),
                't-c' => $this->rank('C', 'Départemental', 4, 0),
            ],
        ));

        // L'ORDRE se lit sur les noms d'équipes : Elite (S) avant RegPlus (A) avant Reg (B)…
        $positions = array_map(static fn (string $needle): int|false => mb_strpos($html, $needle), [
            'Elite 1', 'RegPlus 1', 'Reg 1', 'Dep 1', 'Loisir 1',
        ]);
        foreach ($positions as $p) {
            self::assertIsInt($p, 'chaque équipe doit apparaître dans la matrice');
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'les groupes doivent rester dans l’ordre de rang S→A→B→C→D');

        // ...et AUCUNE étiquette de rang ne doit être imprimée : ni le libellé (« S · Elite »),
        // ni le nom du rang seul, ni le repli « Sans rang » d'une équipe non classée.
        foreach (['S · Elite', 'A · Régional+', 'B · Régional', 'C · Départemental', 'D · Loisir', 'Sans rang', 'rank-title'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, \sprintf('« %s » ne doit pas être imprimé dans un document public', $forbidden));
        }
    }

    /**
     * Falsification (a) — chaque groupe de rang est un `<tbody>` marqué, et le document
     * garde la règle `break-inside: avoid` qui l'empêche d'être coupé par la pagination.
     * Retirer cette règle (ou le marquage) fait rougir ce test.
     */
    public function testRankGroupsAreMarkedAndKeptWhole(): void
    {
        $html = $this->buildMatrix($this->data([
            $this->slot('t-a', 'v-a', 1, '18:30'),
            $this->slot('t-b', 'v-b', 3, '20:00'),
        ]));
        self::assertStringContainsString('tbody class="rank-group"', $html, 'chaque groupe de rang est un <tbody> marqué');

        // La règle de non-coupure vit dans le CSS du document enveloppant.
        $document = $this->wrapDocument();
        self::assertMatchesRegularExpression(
            '/tbody\.rank-group\s*\{[^}]*break-inside:\s*avoid/',
            $document,
            'le CSS doit garder chaque groupe de rang entier (break-inside: avoid)',
        );
        // La section matrice ouvre bien une nouvelle page.
        self::assertMatchesRegularExpression(
            '/\.page-matrix\s*\{[^}]*break-before:\s*page/',
            $document,
            'la section matrice doit s’ouvrir sur une nouvelle page (break-before: page)',
        );
    }

    /**
     * Rétro-compatibilité du payload worker (le test worker.js n'existe pas : le conteneur
     * pdf-worker n'a pas puppeteer côté frontend). Un export mono-section envoie EXACTEMENT
     * l'ancien payload — aucune clé `multiSection` ; un export multi-section l'ajoute à true.
     */
    public function testSingleSectionWorkerPayloadIsUnchangedAndMultiSectionAddsTheFlag(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): ResponseInterface {
            $captured[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['success' => true]));
        });

        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();
        $prop = new ReflectionClass(PdfGenerator::class)->getProperty('httpClient');
        $prop->setValue($generator, $client);
        $call = new ReflectionMethod(PdfGenerator::class, 'callWorker');

        $call->invoke($generator, '<html></html>', 'schedule-x-all.pdf', false);
        $call->invoke($generator, '<html></html>', 'schedule-x-all.pdf', true);

        self::assertSame(['html', 'filename', 'landscape'], array_keys($captured[0]), 'mono-section : payload historique inchangé, aucune clé en plus');
        self::assertArrayNotHasKey('multiSection', $captured[0]);
        self::assertTrue($captured[1]['multiSection'] ?? null, 'multi-section : le drapeau est ajouté à true');
    }

    /**
     * Esthétique de la grille (section 1) — composition d'une cellule occupée : heure en
     * tête, nom(s) d'équipe empilés en dessous, JAMAIS le coach ni le gymnase (ce dernier
     * étant déjà l'en-tête de colonne). Un créneau partagé empile les noms, un par ligne,
     * sans diviser la cellule en sous-cellules.
     */
    public function testGridCellShowsStartTimeThenStackedTeamsWithoutCoachOrRepeatedVenue(): void
    {
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-1'), $this->slot('t-b', 'v-a', 2, '18:00', 'c-1')],
            6,
            ['t-a' => 'U9F1', 't-b' => 'U9F2'],
            ['v-a' => ['name' => 'Gymnase Municipal', 'color' => '#FFD700']],
            [],
            false,
        );

        // Heure de début en HAUT de la cellule.
        self::assertStringContainsString('class="cell-time">18:00', $html);
        // Les deux équipes empilées, une par ligne — jamais fondues en sous-cellules.
        self::assertStringContainsString('U9F1', $html);
        self::assertStringContainsString('U9F2', $html);
        self::assertSame(2, substr_count($html, 'class="entry"'), 'un nom d’équipe par ligne (pile, pas de division de cellule)');
        // Le gymnase n'est PAS répété dans la cellule (il EST l'en-tête de colonne).
        self::assertStringNotContainsString('Gymnase Municipal', $html);
        // Plus de sous-ligne gymnase/coach : le coach a quitté la grille (surcharge).
        self::assertStringNotContainsString('class="sub"', $html, 'la sous-ligne gymnase/coach a disparu de la cellule');
    }

    /**
     * Esthétique de la matrice (section 2) — plus de pastille : la couleur du gymnase
     * REMPLIT la case (bloc plein), texte centré. Deux séances le même jour empilent deux
     * blocs pleins qui, ensemble, remplissent la case.
     */
    public function testMatrixCellFillsWholeCellWithColourInsteadOfABadge(): void
    {
        $html = $this->buildMatrix($this->data([
            $this->slot('t-a', 'v-a', 1, '18:30'),
            $this->slot('t-b', 'v-b', 3, '20:00'),
        ]));

        // Plus de « chip » ; un bloc plein qui porte la couleur du gymnase en fond.
        self::assertStringNotContainsString('class="chip"', $html, 'plus de pastille — la couleur remplit la case');
        self::assertMatchesRegularExpression('/class="fill" style="background:#[0-9A-Fa-f]{6};color:/', $html, 'le bloc de la case porte la couleur du gymnase');
    }

    /**
     * Le CSS enveloppant porte les marques esthétiques de la grille : bande verticale des
     * jours, pause méridienne délimitée, bordure renforcée des cellules occupées.
     */
    public function testGridCssMarksDayBoundaryNoonBandAndOccupiedCells(): void
    {
        $document = $this->wrapDocument();

        self::assertMatchesRegularExpression('/\.day-start\s*\{[^}]*border-left:\s*2px solid/', $document, 'une bande verticale marque la frontière des jours');
        self::assertStringContainsString('td.cell.noon', $document, 'la pause méridienne est délimitée sur les cellules');
        self::assertMatchesRegularExpression('/td\.filled\s*\{[^}]*border:\s*2px solid/', $document, 'les cellules occupées ont une bordure plus marquée que la grille de base');
    }

    public function testAVenueScopedMatrixDropsTeamsThatTrainElsewhere(): void
    {
        // Deux équipes, deux gymnases — mais l'export est limité à « v-a ». P3-20 : depuis que la
        // vue « club » peut DEMANDER la matrice, elle peut s'ouvrir sur une portée réduite ; y
        // lister l'équipe de l'autre gymnase la ferait passer pour une équipe sans entraînement.
        $data = $this->data([$this->slot('t-a', 'v-a', 1, '18:30')]);

        $scoped = $this->buildMatrix($data, 'v-a');
        self::assertStringContainsString('U13 F', $scoped);
        self::assertStringNotContainsString('U15 M', $scoped, 'une équipe qui s’entraîne ailleurs n’a pas de ligne vide sur un export scopé');

        // Portée complète : l'équipe sans séance GARDE sa ligne — c'est le trou à voir.
        self::assertStringContainsString('U15 M', $this->buildMatrix($data));
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

    /**
     * @return array{label: string, name: string, tierRank: int, tierOrder: int}
     */
    private function rank(string $label, string $name, int $tierRank, int $tierOrder): array
    {
        return ['label' => $label, 'name' => $name, 'tierRank' => $tierRank, 'tierOrder' => $tierOrder];
    }

    /**
     * @param list<ScheduleSlotTemplate>                                                $slots
     * @param array<string, string>                                                     $teamNames
     * @param array<string, string>                                                     $teamCategories
     * @param array<string, string>                                                     $coachNames
     * @param array<string, array{label:string,name:string,tierRank:int,tierOrder:int}> $teamRanks
     */
    private function data(array $slots, ?array $teamNames = null, array $teamCategories = [], array $coachNames = [], ?array $teamRanks = null): ScheduleExportData
    {
        $teamNames ??= ['t-a' => 'U13 F', 't-b' => 'U15 M'];
        $teamRanks ??= [
            't-a' => $this->rank('A', 'Régional+', 2, 0),
            't-b' => $this->rank('B', 'Régional', 3, 0),
        ];

        return new ScheduleExportData(
            slots: $slots,
            teamNames: $teamNames,
            teamCategories: $teamCategories,
            venues: ['v-a' => ['name' => 'Gymnase Municipal', 'color' => '#FFD700'], 'v-b' => ['name' => 'Salle B', 'color' => '#3498DB']],
            coachNames: $coachNames,
            teamRanks: $teamRanks,
        );
    }

    private function hasMatrix(ScheduleExportData $data): bool
    {
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();

        return (bool) new ReflectionMethod(PdfGenerator::class, 'hasMatrix')->invoke($generator, $data);
    }

    private function buildMatrix(ScheduleExportData $data, ?string $venueId = null): string
    {
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();

        return (string) new ReflectionMethod(PdfGenerator::class, 'buildMatrixSection')->invoke($generator, $data, $venueId);
    }

    /**
     * @param list<ScheduleSlotTemplate>                      $bucket
     * @param array<string, string>                           $teamNames
     * @param array<string, array{name:string,color:?string}> $venues
     * @param array<string, string>                           $groupLabels
     */
    private function slotCell(array $bucket, int $span, array $teamNames, array $venues, array $groupLabels, bool $dayStart): string
    {
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();

        return (string) new ReflectionMethod(PdfGenerator::class, 'slotCell')->invoke($generator, $bucket, $span, $teamNames, $venues, $groupLabels, $dayStart);
    }

    private function wrapDocument(): string
    {
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();

        return (string) new ReflectionMethod(PdfGenerator::class, 'wrapDocument')->invoke($generator, 'T', 'scope', 'sched', '<body/>', '');
    }

    /** Le fragment `<tr>…</tr>` contenant un libellé donné (pour asserter une ligne précise). */
    private function rowContaining(string $html, string $needle): string
    {
        foreach (explode('<tr>', $html) as $chunk) {
            if (str_contains($chunk, $needle)) {
                return $chunk;
            }
        }
        self::fail(\sprintf('aucune ligne ne contient « %s »', $needle));
    }
}
