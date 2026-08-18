<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OÙ UN DOC VIT — la règle de placement, rendue opposable.
 *
 * ⚑ Ce test existe parce que la bonne volonté n'a pas suffi : mesuré le 2026-08-18, **~4 000
 * lignes de doc de ZONE** s'étaient accumulées dans `specs/courantes/` (inventaires backend et
 * engine, composants/spec/wizard/stratégie du frontend) pendant que `frontend/docs/` ne contenait
 * qu'un seul fichier. Un dev qui débarquait dans `frontend/` n'y trouvait pas la doc du frontend.
 *
 * La règle (skill `documentation-update`) : un doc qui décrit **UNE SEULE zone** vit dans cette
 * zone ; celui qui décrit la **couture entre zones** vit à la racine ; `specs/` garde ce que
 * `docs/` ne sait pas dire — l'axe TEMPS du produit (initiales → courantes → evolution).
 *
 * `specs/courantes/` n'est donc PAS le dossier du « courant » : c'est celui du **produit**
 * courant. Ce test tient la frontière par le nom de fichier, qui est le signal le plus honnête :
 * un fichier qui s'appelle `frontend-…` annonce lui-même la zone qu'il décrit.
 */
#[Group('phase1')]
final class DocPlacementTest extends TestCase
{
    /** Les zones du dépôt, telles qu'un nom de fichier les annonce. */
    private const ZONES = ['frontend', 'backend', 'engine', 'landing'];

    /**
     * Les exceptions, NOMMÉES et justifiées — jamais un motif large.
     *
     * `openapi-snapshot` décrit le CONTRAT que le frontend et le backend partagent : il n'est
     * la propriété d'aucune des deux zones, et le ranger dans l'une obligerait l'autre à le
     * dupliquer. Il reste donc en `specs/courantes/` jusqu'à ce qu'on lui trouve mieux.
     */
    private const ALLOWED = ['openapi-snapshot.meta.md', 'openapi-snapshot.json'];

    public function testNoZoneDocHidesInTheProductSpecs(): void
    {
        $misplaced = [];
        foreach (glob(\dirname(__DIR__, 3) . '/../specs/courantes/*') ?: [] as $path) {
            $name = basename($path);
            if (\in_array($name, self::ALLOWED, true)) {
                continue;
            }
            foreach (self::ZONES as $zone) {
                if (str_starts_with($name, $zone)) {
                    $misplaced[] = \sprintf('specs/courantes/%s → %s/docs/%s', $name, $zone, $name);
                }
            }
        }

        self::assertSame([], $misplaced, <<<'TXT'
            Ces documents décrivent UNE zone : ils appartiennent à cette zone, pas aux specs produit.

            `specs/courantes/` tient ce que l'APPLICATION fait aujourd'hui, tous zones confondues —
            pas l'inventaire des routes d'une zone ni la liste des composants d'une autre. Un dev
            qui débarque dans une zone doit trouver la doc de cette zone DANS cette zone.

            Déplacez le fichier, puis reprenez les liens qui le citent (ils sont nombreux : le
            dernier déplacement en a repris 59). Si vous croyez à une exception, elle se déclare
            NOMMÉMENT dans self::ALLOWED, avec sa raison — jamais par un motif large.
            TXT);
    }

    /**
     * Le pendant du test ci-dessus : les zones ont réellement leur dossier, et il n'est pas vide.
     * Sans ça, la règle pourrait « tenir » sur un dépôt où personne n'écrit plus rien nulle part.
     */
    public function testEveryCodeZoneOwnsItsDocs(): void
    {
        $root = \dirname(__DIR__, 3) . '/..';
        foreach (['frontend', 'backend', 'engine'] as $zone) {
            $docs = glob($root . '/' . $zone . '/docs/*.md') ?: [];
            self::assertNotSame([], $docs, \sprintf('la zone « %s » doit porter sa propre doc dans %s/docs/', $zone, $zone));
        }
    }
}
