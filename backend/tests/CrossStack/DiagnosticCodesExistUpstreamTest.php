<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D-41 — les codes de diagnostic vivent dans trois zones, sans enum ni contrat.
 *
 * Le moteur les ÉMET, le backend les traduit en français (`DiagnosticMessageBuilder`), le
 * frontend en cite un pour surligner le gymnase concerné (`DiagnosticsPanel`). Un code
 * renommé côté moteur tombe alors dans le `default` du `match` PHP — le gestionnaire lit
 * **« Diagnostic inconnu. »** — et éteint le surlignage côté écran. Sans erreur, sans test.
 *
 * ⚑ Le schéma engine « documentait » la liste **en commentaire**. Un commentaire ne valide
 * rien, et celui-ci avait déjà dérivé : il annonçait **7 types**, le solveur en émet **11**
 * (`constraint_not_honored`, `day_constraint_conflict`, `venue_minimum_unreachable`,
 * `unplaced_match` manquaient). Il est devenu un `Literal[...]` : Pydantic refuse désormais
 * un type hors liste **à la construction, côté émetteur**.
 *
 * Ce test tient l'autre bout : tout code cité en aval doit exister en amont. Il ne réclame
 * PAS que le backend traite les 11 — le `match` a un `default` qui reprend le message du
 * moteur, et c'est un choix : les codes sans mise en forme française y tombent volontairement.
 */
#[Group('contract')]
final class DiagnosticCodesExistUpstreamTest extends TestCase
{
    private const string ENGINE_SCHEMA = __DIR__ . '/../../../engine/app/schemas/output_schema.py';

    private const string PHP_BUILDER = __DIR__ . '/../../src/Service/DiagnosticMessageBuilder.php';

    private const string TS_PANEL = __DIR__ . '/../../../frontend/src/features/planning/DiagnosticsPanel.tsx';

    public function testEveryCodeHandledByTheBackendIsEmittedByTheEngine(): void
    {
        $upstream = $this->engineCodes();

        $source = file_get_contents(self::PHP_BUILDER);
        self::assertIsString($source);

        // Les bras du `match ($type)` : 'code' => …
        preg_match_all("/^\s+'([a-z_]+)' => /m", $source, $found);
        $handled = array_unique($found[1]);
        self::assertNotEmpty($handled, 'Aucun code trouvé dans DiagnosticMessageBuilder — le `match` a-t-il changé de forme ?');

        $ghosts = array_values(array_diff($handled, $upstream));

        self::assertSame([], $ghosts, \sprintf(
            "DiagnosticMessageBuilder met en forme des codes que le moteur n'émet plus : %s.\n"
            . "Ces bras du `match` sont morts : le diagnostic réel tombe dans le `default` et le\n"
            . 'gestionnaire lit « Diagnostic inconnu. » au lieu du message soigné prévu pour lui.',
            implode(', ', $ghosts),
        ));
    }

    public function testEveryCodeCitedByTheFrontendIsEmittedByTheEngine(): void
    {
        $upstream = $this->engineCodes();

        $source = file_get_contents(self::TS_PANEL);
        self::assertIsString($source);

        preg_match_all('/"([a-z_]+)" === diagnostic\.type/', $source, $found);
        $cited = array_unique($found[1]);
        self::assertNotEmpty($cited, 'Le panneau ne compare plus aucun code — la comparaison a-t-elle changé de forme ?');

        $ghosts = array_values(array_diff($cited, $upstream));

        self::assertSame([], $ghosts, \sprintf(
            "Le panneau de diagnostics réagit à des codes que le moteur n'émet plus : %s.\n"
            . 'Le surlignage du gymnase concerné ne se déclenche donc jamais — en silence.',
            implode(', ', $ghosts),
        ));
    }

    /**
     * Les codes déclarés par le `Literal` du schéma engine — la source, désormais contraignante.
     *
     * @return list<string>
     */
    private function engineCodes(): array
    {
        $schema = file_get_contents(self::ENGINE_SCHEMA);
        self::assertIsString($schema, \sprintf('Illisible : %s', self::ENGINE_SCHEMA));

        $found = preg_match('/type: Literal\[(.*?)\]/s', $schema, $matches);
        self::assertSame(1, $found, 'Le `Literal` des types de diagnostic a disparu du schéma engine — il était la seule contrainte réelle sur cette liste.');

        preg_match_all('/"([a-z_]+)"/', $matches[1], $codes);
        self::assertNotEmpty($codes[1], 'Le `Literal` ne porte plus aucun code.');

        return $codes[1];
    }
}
