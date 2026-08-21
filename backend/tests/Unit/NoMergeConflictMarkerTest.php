<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * **Aucun fichier suivi ne contient de marqueur de conflit.** Trivial à dire, invisible à l'œil.
 *
 * ⚑ Écrit après l'avoir payé : la PR #682 (retrait de l'export PNG, 2026-08-21) a commité trois
 * marqueurs — `<<<<<<< Updated upstream` / `=======` / `>>>>>>> Stashed changes` — au milieu de
 * `specs/courantes/etat-des-lieux.md`, issus d'un `git stash pop` pendant un rebase. Ils sont
 * arrivés sur `main` et n'ont été vus qu'au rebase SUIVANT, qui a buté dessus.
 *
 * Pourquoi rien ne les a arrêtés : le fichier est un journal en Markdown, aucun test ne lit sa
 * syntaxe, aucun linter ne le traverse, et un diff de 300 lignes de prose ne fait pas ressortir
 * trois lignes de plus. La revue humaine non plus — c'est précisément le genre de détail qu'un
 * relecteur survole.
 *
 * ⚠ La portée est VOLONTAIREMENT tout le dépôt, pas seulement les docs : un marqueur dans du
 * code casse la compilation (donc se voit), mais dans un `.md`, un `.json`, un `.yaml` de config
 * ou un fixture, il vit en silence — et un `.json` corrompu ne se découvre qu'à l'exécution.
 *
 * On interroge `git grep` plutôt que de parcourir le disque : il ne voit QUE les fichiers
 * suivis, donc ni `node_modules/`, ni `var/`, ni les artefacts de test — et il est rapide.
 */
#[Group('phase1')]
final class NoMergeConflictMarkerTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testNoTrackedFileCarriesAConflictMarker(): void
    {
        // Les marqueurs Git en DÉBUT DE LIGNE, la seule forme que produit un conflit non résolu.
        // `=======` seul n'est pas retenu : c'est un souligné Markdown légitime, et un marqueur
        // ne vient jamais seul — attraper les deux bornes suffit et n'a aucun faux positif.
        $process = Process::fromShellCommandline(
            'git grep -n -E "^(<<<<<<< |>>>>>>> )" -- . ":(exclude)backend/tests/Unit/NoMergeConflictMarkerTest.php" || true',
            self::ROOT,
        );
        $process->run();

        $hits = trim($process->getOutput());

        self::assertSame('', $hits, \sprintf(
            "Des marqueurs de conflit Git sont COMMITÉS dans ces fichiers :\n%s\n"
            . "Un `git stash pop` ou un merge a été laissé à moitié résolu. Rouvrez le fichier, gardez les DEUX\n"
            . 'contenus si les deux sont légitimes (c’est le cas d’un journal où deux PR ajoutent chacune leur ligne),'
            . ' et supprimez les trois lignes de marqueurs.',
            $hits,
        ));
    }
}
