<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — le stack Symfony reste homogène sur la LTS 7.4 (P4-31).
 *
 * `extra.symfony.require: "7.4.*"` est un réglage FLEX, pas Composer : il ne
 * contraint que les `symfony/*` listés en require DIRECTE. Tout paquet
 * purement transitif y échappe, et comme leurs requirants acceptent
 * `^7.4|^8.0`, Composer prend le plus haut. C'est ainsi que 19 paquets — dont
 * `http-kernel`, `dependency-injection`, `routing`, `security-core` — ont
 * dérivé en 8.0.x sous des bundles 7.4.x, sans qu'aucun outil ne le signale.
 *
 * Le coût n'est pas théorique. `UserInterface::eraseCredentials` a disparu de
 * l'interface INSTALLÉE, Rector a supprimé la méthode de `User`, et le code
 * compilait toujours : le conteneur n'aurait explosé qu'au réalignement. Plus
 * grave à terme, la branche 8.0 sort de support en juillet 2026 — une CVE sur
 * `http-kernel` n'y serait jamais corrigée, et `composer audit` (bloquant en
 * CI) rougirait sans issue.
 *
 * Ce test lit le lock et échoue dès qu'un paquet repart hors 7.4. Il est
 * FAIL-CLOSED : un `symfony/*` inconnu doit être en 7.4 par défaut, donc un
 * nouveau transitif introduit par un bump se signale tout seul.
 */
#[Group('phase1')]
final class SymfonyStackAlignmentTest extends TestCase
{
    /**
     * Les familles `symfony/*` qui ont leur PROPRE versionnage et ne suivent
     * pas les majeures du framework. Elles sont contraintes malgré tout : les
     * lister ne doit pas revenir à les dispenser de toute vérification, sinon
     * l'exception devient la porte de sortie qu'on cherche à fermer.
     *
     * @var array<string, string> motif de nom => préfixe de version attendu
     */
    private const INDEPENDENT_FAMILIES = [
        '-contracts' => 'v3.',   // contrats : majeure 3.x depuis Symfony 5
        'polyfill-' => 'v1.',    // polyfills : majeure 1.x, indépendante
        'flex' => 'v2.',         // plugin Composer
        'mercure' => 'v0.',      // composant + bundle Mercure
    ];

    private const EXPECTED_FRAMEWORK_PREFIX = 'v7.4.';

    public function testEverySymfonyFrameworkPackageStaysOnTheLtsBranch(): void
    {
        $drifted = [];
        foreach ($this->lockedSymfonyPackages() as $name => $version) {
            if (null !== $this->independentFamilyPrefix($name)) {
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
                . 'Corriger en les nommant explicitement en `7.4.*` dans composer.json '
                . "(`extra.symfony.require` ne couvre PAS les transitifs), puis\n"
                . '`composer update <paquets> --with-all-dependencies`.',
                implode("\n", array_map(
                    static fn (string $n, string $v): string => "  - {$n} {$v}",
                    array_keys($drifted),
                    $drifted,
                )),
            ),
        );
    }

    /**
     * Le garde-fou du garde-fou : une famille « indépendante » reste bornée.
     * Sans ceci, élargir `INDEPENDENT_FAMILIES` suffirait à faire taire le
     * test au lieu de corriger la dérive.
     */
    public function testIndependentlyVersionedFamiliesStayOnTheirOwnMajor(): void
    {
        $unexpected = [];
        foreach ($this->lockedSymfonyPackages() as $name => $version) {
            $prefix = $this->independentFamilyPrefix($name);
            if (null !== $prefix && !str_starts_with($version, $prefix)) {
                $unexpected[$name] = $version;
            }
        }

        self::assertSame([], $unexpected, 'Une famille à versionnage propre a changé de majeure : à revalider à la main avant d’élargir l’exception.');
    }

    /**
     * Le stack doit rester substantiel : si le lock ne remontait plus qu'une
     * poignée de paquets (chemin faux, format changé), les deux tests ci-dessus
     * passeraient au vert sur du vide.
     */
    public function testTheLockActuallyDescribesTheSymfonyStack(): void
    {
        self::assertGreaterThan(40, \count($this->lockedSymfonyPackages()));
    }

    /** @return array<string, string> nom du paquet => version verrouillée */
    private function lockedSymfonyPackages(): array
    {
        $raw = file_get_contents(\dirname(__DIR__, 3) . '/composer.lock');
        self::assertIsString($raw, 'composer.lock illisible');

        $lock = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            /** @var list<array{name: string, version: string}> $entries */
            $entries = \is_array($lock[$section] ?? null) ? $lock[$section] : [];
            foreach ($entries as $package) {
                if (str_starts_with($package['name'], 'symfony/')) {
                    $packages[$package['name']] = $package['version'];
                }
            }
        }

        return $packages;
    }

    /** Le préfixe de version attendu si le paquet relève d'une famille indépendante, sinon null. */
    private function independentFamilyPrefix(string $name): ?string
    {
        foreach (self::INDEPENDENT_FAMILIES as $needle => $prefix) {
            if (str_contains($name, $needle)) {
                return $prefix;
            }
        }

        return null;
    }
}
