<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * AUD-DOC-04 — un « Last verified » qui ment est PIRE que pas de stamp du tout.
 *
 * Le motif a survécu à SIX éditions d'audit (2026-07-03 → 2026-08-08) parce qu'il ne portait
 * pas sur le fond : au 2026-08-08, 9 fichiers sur 16 portaient un stamp antérieur à leur
 * dernière édition, et pourtant AUCUN n'avait un contenu périmé — chacun avait été recalé par
 * la PR qui livrait sa feature. Seul le stamp restait figé. Personne ne pouvait le voir en
 * lisant le fichier : il fallait comparer une date à l'historique git.
 *
 * D'où ce garde. La propriété est mécanique et ne juge PAS la qualité du contenu :
 *
 *     un fichier édité APRÈS la date de son stamp a un stamp qui ment.
 *
 * Il ne prouve donc pas qu'un fichier est exact — il prouve que sa date n'est pas déjà
 * contredite par git. C'est le seul volet automatisable ; la relecture, elle, reste humaine
 * (cf. `specs/README.md` §« La règle du stamp », qui distingue « re-vérifié contre le code »
 * de « recalé par les livraisons »).
 *
 * ⚠ Quand ce test rougit, le réflexe N'EST PAS de bumper la date : c'est de regarder ce que
 * les commits ont changé (`git log --since=<stamp> -- <fichier>`), de vérifier ce point,
 * PUIS de dater en disant laquelle des deux formes s'applique. Bumper sans vérifier
 * transforme une promesse vague en fausse garantie — le mensonge devient crédible.
 */
#[Group('phase1')]
final class DocStampFreshnessTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * Les documents dont la fraîcheur engage un agent qui les lit sans relire le code.
     *
     * ⚑ AUD-DOC-31 (2026-08-19) — les `<zone>/docs/` sont entrés ici APRÈS coup, et c'est la
     * leçon : la migration doc du 2026-08-18 a sorti six documents de `specs/courantes/` vers
     * leur zone, et ils ont quitté ce garde EN SILENCE. Dont `backend/docs/backend-inventory.md`,
     * le récidiviste cinq fois de DOC-04 — la protection durement acquise contre lui s'était
     * rétractée sur lui, sans que rien ne le dise. Déplacer un fichier peut le faire sortir
     * d'un filet : le filet doit suivre.
     *
     * Des GLOBS de zone, et non une liste : un doc de zone créé demain est surveillé d'office.
     * Les fichiers encore sans stamp sont exemptés NOMMÉMENT ci-dessous — visibles, comptés,
     * et à résorber (P4-120), plutôt qu'invisibles.
     */
    private const array WATCHED = [
        'specs/courantes/*.md',
        'specs/README.md',
        'docs/project-map.md',
        'docs/testing/testing-strategy.md',
        'backend/docs/*.md',
        'engine/docs/*.md',
        'frontend/docs/*.md',
    ];

    /**
     * Exemptions NOMINATIVES, avec leur raison — jamais une famille de fichiers.
     *
     * @var array<string, string>
     */
    private const array EXEMPT = [
        // Journal : chaque LIGNE porte déjà sa date de livraison. Un stamp global y serait
        // redondant, et il mentirait à chaque nouvelle trace ajoutée.
        'specs/courantes/etat-des-lieux.md' => 'journal de traces datées ligne par ligne',
        // Snapshot généré : sa fraîcheur se prouve par le snapshot lui-même, pas par une date.
        'specs/courantes/openapi-snapshot.json' => 'artefact généré, pas de la prose',
        // ── Dette AUD-DOC-31 / P4-120 ────────────────────────────────────────────────────
        // Ces docs de zone n'ont JAMAIS porté de stamp : ils ne vivaient pas dans
        // `specs/courantes/`, donc la migration ne leur a rien retiré — ce n'est pas une
        // régression, c'est un trou préexistant que l'élargissement du garde rend enfin
        // VISIBLE. Chacun sort de cette liste le jour où quelqu'un le vérifie et le date :
        // poser un stamp sans avoir vérifié serait précisément le mensonge que ce test
        // traque. Aucune ligne ne s'AJOUTE ici — un doc de zone neuf naît avec son stamp.
        'backend/docs/commands.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/constraint-config-keys.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/constraint-coverage.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/constraints.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/ffbb-api.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/generation-flow.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/RLS.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/schedule-generation-guide.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'backend/docs/TENANT.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'engine/docs/business.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'engine/docs/nominal-flow.md' => 'jamais stampé (antérieur au garde) — P4-120',
        'engine/docs/solver-errors.md' => 'jamais stampé (antérieur au garde) — P4-120',
    ];

    public function testEveryWatchedDocumentCarriesAStamp(): void
    {
        $missing = [];
        foreach ($this->watchedFiles() as $relative => $absolute) {
            if (null === $this->stampOf($absolute)) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, \sprintf(
            "Ces documents n'ont pas de ligne « Last verified @ YYYY-MM-DD » :\n  - %s\n"
            . 'Ajoutez-la sous le titre, ou exemptez le fichier NOMMÉMENT dans self::EXEMPT avec sa raison.',
            implode("\n  - ", $missing),
        ));
    }

    public function testNoStampIsOlderThanTheLastEditOfItsFile(): void
    {
        $this->skipIfHistoryIsUnusable();

        $liars = [];
        foreach ($this->watchedFiles() as $relative => $absolute) {
            $stamp = $this->stampOf($absolute);
            if (null === $stamp) {
                continue; // couvert par l'autre test — un seul échec par cause.
            }

            $lastEdit = $this->lastCommitDate($relative);
            if (null !== $lastEdit && $lastEdit > $stamp) {
                $liars[] = \sprintf('%s (stamp=%s, dernière édition=%s)', $relative, $stamp, $lastEdit);
            }
        }

        self::assertSame([], $liars, \sprintf(
            "Ces stamps sont antérieurs à la dernière édition de leur fichier :\n  - %s\n"
            . "NE PAS se contenter de bumper la date. Pour chacun : `git log --since=<stamp> -- <fichier>`,\n"
            . "vérifier ce que ces commits ont changé, PUIS dater en nommant ce qui a été recalé\n"
            . '(cf. specs/README.md §« La règle du stamp »).',
            implode("\n  - ", $liars),
        ));
    }

    /** @return array<string, string> chemin relatif au dépôt => chemin absolu */
    private function watchedFiles(): array
    {
        $files = [];
        foreach (self::WATCHED as $pattern) {
            foreach (glob(self::ROOT . '/' . $pattern) ?: [] as $absolute) {
                $relative = $this->relativePath($absolute);
                if (!isset(self::EXEMPT[$relative])) {
                    $files[$relative] = $absolute;
                }
            }
        }
        ksort($files);

        self::assertNotEmpty($files, 'Aucun document surveillé trouvé — self::WATCHED pointe-t-il encore quelque part ?');

        return $files;
    }

    private function relativePath(string $absolute): string
    {
        $root = realpath(self::ROOT);
        self::assertIsString($root);

        return ltrim(str_replace($root, '', (string) realpath($absolute)), '/');
    }

    /** La date du stamp au format Y-m-d, ou null si le fichier n'en porte pas. */
    private function stampOf(string $absolute): ?string
    {
        $contents = file_get_contents($absolute);
        self::assertIsString($contents, \sprintf('Illisible : %s', $absolute));

        return 1 === preg_match('/Last verified @ (\d{4}-\d{2}-\d{2})/', $contents, $m)
            ? $m[1]
            : null;
    }

    /** La date du dernier commit ayant touché le fichier, ou null si l'historique n'en sait rien. */
    private function lastCommitDate(string $relative): ?string
    {
        $git = new Process(['git', 'log', '-1', '--format=%ad', '--date=short', '--', $relative], self::ROOT);
        $git->run();

        $date = trim($git->getOutput());

        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /**
     * Un clone superficiel ne connaît qu'un commit : `git log -- <fichier>` y est muet pour
     * presque tout, et le test passerait au vert en ne regardant rien. Mieux vaut un skip
     * bruyant qu'un faux vert — le job qui lance ce test doit poser `fetch-depth: 0`.
     */
    private function skipIfHistoryIsUnusable(): void
    {
        $inRepo = new Process(['git', 'rev-parse', '--is-inside-work-tree'], self::ROOT);
        $inRepo->run();
        if (!$inRepo->isSuccessful()) {
            self::markTestSkipped('Pas de dépôt git exploitable ici — la fraîcheur des stamps ne peut pas être mesurée.');
        }

        $shallow = new Process(['git', 'rev-parse', '--is-shallow-repository'], self::ROOT);
        $shallow->run();
        if ('true' === trim($shallow->getOutput())) {
            self::markTestSkipped(
                'Clone superficiel : ajoutez `fetch-depth: 0` au checkout du job qui lance ce test, '
                . 'sinon ce garde ne voit rien.',
            );
        }
    }
}
