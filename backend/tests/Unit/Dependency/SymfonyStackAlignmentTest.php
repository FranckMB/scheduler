<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dependency;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — le stack Symfony reste homogène sur la LTS 7.4 (P4-31).
 *
 * Flex CONTRAINT bien les splits Symfony à `extra.symfony.require`, transitifs
 * compris (`PackageFilter::removeLegacyPackages()` ne teste jamais si le paquet
 * est en require directe). Mais il exempte tout paquet dont la version
 * candidate est DÉJÀ celle du lock. C'est là que la dérive s'installe : une
 * fois `http-kernel` verrouillé en 8.0.14, chaque mise à jour partielle le
 * laisse tel quel, et plus rien ne le ramène — 19 paquets ont vécu des
 * semaines en 8.0.x sous des bundles 7.4.x sans qu'aucun outil ne le signale.
 *
 * Le coût n'est pas théorique. `UserInterface::eraseCredentials` a disparu de
 * l'interface INSTALLÉE, Rector a supprimé la méthode de `User`, et le code
 * compilait toujours : le conteneur n'aurait explosé qu'au réalignement. Plus
 * grave à terme, la branche 8.0 sort de support en juillet 2026 — une CVE sur
 * `http-kernel` n'y serait jamais corrigée, et `composer audit` rougirait sans
 * issue.
 *
 * Ce test lit les versions **INSTALLÉES**, pas le lock. C'est l'installé que
 * lisent Rector, PHPStan et le conteneur — un lock propre au-dessus d'un
 * `vendor/` périmé (bind-mount non rafraîchi) est précisément la configuration
 * où la dérive frappe sans que le lock ne la montre.
 */
#[Group('phase1')]
final class SymfonyStackAlignmentTest extends TestCase
{
    private const EXPECTED_FRAMEWORK_PREFIX = '7.4.';

    /**
     * Les paquets `symfony/*` qui ne suivent PAS les majeures du framework.
     *
     * Inventaire EXACT et ancré, jamais un `str_contains` : « mercure » en
     * sous-chaîne exempterait un futur `symfony/mercure-bridge` — un split
     * versionné 7.4 comme les autres — et le ferait échapper au contrôle.
     *
     * Leur majeure n'est délibérément PAS assertée : figer « mercure en 0.x »
     * ferait rougir un gate sur une release amont légitime (0.7 → 1.0), c'est-
     * à-dire punir un bump dependabot pour un commit qui n'a rien changé.
     * Ce qui est gardé ici, c'est que la LISTE n'enfle pas — voir
     * `testTheExemptionListStaysClosed`.
     *
     * @var list<string> noms exacts
     */
    private const EXEMPT_EXACT = ['symfony/flex', 'symfony/mercure', 'symfony/mercure-bundle', 'symfony/monolog-bundle'];

    /** @var list<string> préfixes ancrés sur le nom complet */
    private const EXEMPT_PREFIXES = ['symfony/polyfill-'];

    /** @var list<string> suffixes ancrés sur le nom complet */
    private const EXEMPT_SUFFIXES = ['-contracts'];

    public function testEverySymfonyFrameworkPackageStaysOnTheLtsBranch(): void
    {
        $drifted = [];
        foreach ($this->installedSymfonyPackages() as $name => $version) {
            if ($this->isExempt($name)) {
                continue;
            }
            if (!str_starts_with($version, self::EXPECTED_FRAMEWORK_PREFIX)) {
                $drifted[$name] = $version;
            }
        }

        self::assertSame(
            [],
            $drifted,
            \sprintf(
                "Ces paquets Symfony ont quitté la LTS 7.4 :\n%s\n"
                . "Correctif : `composer update <ces paquets>` suffit — Flex les re-filtre vers 7.4.*.\n"
                . 'Les épingler dans composer.json est inutile (Flex couvre déjà les transitifs) ; '
                . 'ce qui bloque, c\'est l\'exemption par le lock, que la mise à jour lève.',
                implode("\n", array_map(
                    static fn (string $n, string $v): string => "  - {$n} {$v}",
                    array_keys($drifted),
                    $drifted,
                )),
            ),
        );
    }

    /**
     * Le garde-fou du garde-fou — le VRAI.
     *
     * La version précédente prétendait fermer ce trou en vérifiant la majeure
     * des familles exemptées. Elle ne fermait rien : le contrôle était un
     * `str_starts_with($version, $prefix)` et toute version Composer commence
     * par « v », donc ajouter `'http-' => 'v'` exemptait `http-kernel` en
     * gardant les deux tests verts.
     *
     * Le seul verrou honnête est un INVENTAIRE : la liste d'exceptions est
     * figée ici. L'élargir fait rougir ce test, donc exige de toucher une
     * liste littérale que la revue voit — au lieu d'une ligne de constante
     * qui passerait pour un détail.
     */
    public function testTheExemptionListStaysClosed(): void
    {
        self::assertSame(
            // monolog-bundle ajouté au GO fondateur du 2026-08-13 (lot corrélation) :
            // même cas que mercure-bundle — le bundle wrapper est versionné à part
            // (4.x), le VRAI pont Symfony (symfony/monolog-bridge) est, lui, sous le
            // filtre LTS et installé en 7.4.x.
            ['symfony/flex', 'symfony/mercure', 'symfony/mercure-bundle', 'symfony/monolog-bundle'],
            self::EXEMPT_EXACT,
            'Élargir la liste d’exceptions exempte des paquets du contrôle LTS : à justifier en revue, pas à glisser dans une constante.',
        );
        self::assertSame(['symfony/polyfill-'], self::EXEMPT_PREFIXES);
        self::assertSame(['-contracts'], self::EXEMPT_SUFFIXES);
    }

    /**
     * Le stack doit rester substantiel : si l'inventaire ne remontait plus
     * qu'une poignée de paquets (API Composer changée, autoload absent), le
     * test ci-dessus passerait au vert sur du vide.
     */
    public function testTheInstalledSetActuallyDescribesTheSymfonyStack(): void
    {
        self::assertGreaterThan(40, \count($this->installedSymfonyPackages()));
    }

    /**
     * Les versions RÉELLEMENT chargées par ce process.
     *
     * @return array<string, string> nom du paquet => version installée
     */
    private function installedSymfonyPackages(): array
    {
        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $name) {
            if (!str_starts_with($name, 'symfony/')) {
                continue;
            }
            $version = InstalledVersions::getPrettyVersion($name);
            if (null !== $version) {
                $packages[$name] = ltrim($version, 'v');
            }
        }

        return $packages;
    }

    private function isExempt(string $name): bool
    {
        if (\in_array($name, self::EXEMPT_EXACT, true)) {
            return true;
        }
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        foreach (self::EXEMPT_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
