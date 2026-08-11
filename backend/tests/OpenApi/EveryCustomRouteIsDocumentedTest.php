<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * P4-47 — une route `#[Route]` custom n'existe pour personne tant qu'elle n'est pas déclarée.
 *
 * API Platform documente ses propres opérations ; toute route Symfony écrite à la main est
 * **invisible** de `/api/docs` et du snapshot tant que `CustomRoutesOpenApiFactory` ne la
 * déclare pas. Un développeur front — ou un agent qui planifie — lit un contrat où elle
 * n'apparaît pas, et conclut qu'elle n'existe pas.
 *
 * ⚑ **Le test de dérive de FRT-19 ne pouvait PAS attraper ça** : il compare le contrat vivant
 * au snapshot, et une route absente de la factory manque des **deux** côtés. Deux artefacts
 * parfaitement d'accord entre eux, et faux tous les deux — exactement le motif relevé sur
 * `VENUE_AT_MOST_ONE`. Il fallait confronter le contrat au **routeur**, pas à lui-même.
 *
 * ⚠ **Ce test était un CLIQUET ; la baseline ayant atteint zéro (P4-47), c'est désormais un
 * MUR.** `KNOWN_UNDOCUMENTED` est vide : toute route custom non déclarée dans
 * `CustomRoutesOpenApiFactory` fait rougir, sans échappatoire. Le second sens du cliquet
 * (une route documentée doit SORTIR de la liste) reste gardé — il est ce qui a empêché la
 * baseline de pourrir en liste d'excuses pendant qu'elle se vidait.
 */
#[Group('phase1')]
final class EveryCustomRouteIsDocumentedTest extends KernelTestCase
{
    /**
     * Surfaces hors contrat public, exclues par NATURE et non par dette.
     *
     * `/api/dev/*` n'existe qu'en dev (horloge de test, raccourcis e2e) ; les autres sont
     * des routes d'infrastructure d'API Platform (contexte JSON-LD, page d'erreurs de
     * validation, point d'entrée) qui ne décrivent aucune capacité métier.
     */
    private const array OUT_OF_SCOPE_PREFIXES = [
        '/api/dev/',
        '/api/contexts',
        '/api/validation_errors',
        '/api/.well-known',
        '/api/docs',
        '/api/errors',
    ];

    /** Le point d'entrée généré par API Platform, qui n'est pas une route métier. */
    private const string ENTRYPOINT = '/api/{index}.{_format}';

    /**
     * ⚠ DETTE DÉCLARÉE — **vide depuis P4-47** (2026-08-11), et c'est l'état à défendre.
     *
     * La baseline du 2026-08-09 portait 15 routes hors contrat (10 sur la console
     * superadmin, 2 pages publiques à token, 3 utilitaires FFBB) ; elles sont toutes
     * déclarées dans `CustomRoutesOpenApiFactory`. Le cliquet a fait son travail : la
     * liste ne pouvait que rétrécir, elle a atteint zéro.
     *
     * ⚠ **Ne la re-remplissez pas.** Elle n'existe plus que comme mécanisme ; y rajouter
     * une ligne, c'est rouvrir la dette pour de bon. Une route custom nouvelle se
     * documente, elle ne se déclare pas exemptée — le seul motif légitime d'exemption
     * est « hors contrat public par NATURE », et il a sa propre liste
     * (`OUT_OF_SCOPE_PREFIXES`), séparée précisément pour que les deux ne se confondent
     * pas.
     */
    private const array KNOWN_UNDOCUMENTED = [];

    public function testNoCustomRouteEscapesTheContract(): void
    {
        $undocumented = $this->undocumentedCustomRoutes();
        $unexpected = array_values(array_diff($undocumented, self::KNOWN_UNDOCUMENTED));

        self::assertSame([], $unexpected, \sprintf(
            "Ces routes custom n'apparaissent nulle part dans le contrat OpenAPI :\n  - %s\n\n"
            . "Une route absente du contrat n'existe pour personne : le frontend écrit ses types\n"
            . "à partir de lui, et un agent qui planifie le lit comme la vérité.\n"
            . 'Déclarez-la dans `CustomRoutesOpenApiFactory` (réflexe : nouvelle route custom = entrée factory).',
            implode("\n  - ", $unexpected),
        ));
    }

    /**
     * Le second sens, sans lequel la baseline deviendrait une liste d'excuses : une route
     * qu'on vient de documenter doit SORTIR de `KNOWN_UNDOCUMENTED`.
     */
    public function testTheDebtBaselineOnlyShrinks(): void
    {
        $undocumented = $this->undocumentedCustomRoutes();
        $stale = array_values(array_diff(self::KNOWN_UNDOCUMENTED, $undocumented));

        self::assertSame([], $stale, \sprintf(
            "Ces routes sont désormais documentées mais figurent encore dans la dette déclarée :\n  - %s\n\n"
            . 'Retirez-les de `KNOWN_UNDOCUMENTED` — la baseline ne vaut que si elle décroît.',
            implode("\n  - ", $stale),
        ));
    }

    /** @return list<string> */
    private function undocumentedCustomRoutes(): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $router = $container->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $factory = $container->get(OpenApiFactoryInterface::class);
        \assert($factory instanceof OpenApiFactoryInterface);

        $documented = [];
        foreach (array_keys($factory()->getPaths()->getPaths()) as $path) {
            $documented[(string) $path] = true;
        }

        $undocumented = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            // Les opérations générées par API Platform portent un nom `_api_*` : elles sont
            // documentées par construction, ce test ne parle que des routes écrites à la main.
            if (str_starts_with((string) $name, '_api_') || !str_starts_with($path, '/api')) {
                continue;
            }
            if (self::ENTRYPOINT === $path || $this->isOutOfScope($path) || isset($documented[$path])) {
                continue;
            }

            $undocumented[$path] = true;
        }

        $paths = array_keys($undocumented);
        sort($paths);

        return $paths;
    }

    private function isOutOfScope(string $path): bool
    {
        foreach (self::OUT_OF_SCOPE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
