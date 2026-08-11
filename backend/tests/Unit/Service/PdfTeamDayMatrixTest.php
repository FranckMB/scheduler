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

    public function testRankSubtitlesAppearInOrderSToD(): void
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

        $positions = array_map(static fn (string $needle): int|false => mb_strpos($html, $needle), [
            'S · Elite', 'A · Régional+', 'B · Régional', 'C · Départemental', 'D · Loisir',
        ]);
        foreach ($positions as $p) {
            self::assertIsInt($p, 'chaque sous-titre de rang doit être présent');
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'les sous-titres de rang doivent apparaître dans l’ordre S→A→B→C→D');
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

    private function buildMatrix(ScheduleExportData $data): string
    {
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();

        return (string) new ReflectionMethod(PdfGenerator::class, 'buildMatrixSection')->invoke($generator, $data);
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
