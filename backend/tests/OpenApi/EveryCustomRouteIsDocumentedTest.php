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
 * ⚠ **Ce test est un CLIQUET, pas un mur.** La dette existante est déclarée dans
 * `KNOWN_UNDOCUMENTED` : la liste est une baseline qui ne peut que **rétrécir**. Une route
 * ajoutée sans documentation fait rougir ; une route documentée doit sortir de la liste,
 * sinon le test le dit aussi. Sans ce second sens, la baseline pourrirait en liste
 * d'excuses.
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
     * ⚠ DETTE DÉCLARÉE — baseline du 2026-08-09, à faire DÉCROÎTRE.
     *
     * Chaque ligne est une capacité réelle que le contrat public ne montre pas. Elles sont
     * listées plutôt que tolérées en silence : une dette qu'on peut compter se résorbe, une
     * dette invisible s'installe. Documenter une route = la retirer d'ici.
     *
     * Les 10 routes `/api/admin/**` sont groupées à part : la console superadmin a son
     * propre contrat (`specs/courantes/superadmin-auth.md`) et n'est pas consommée par le
     * frontend club — sa documentation OpenAPI a donc une valeur moindre, pas nulle.
     */
    private const array KNOWN_UNDOCUMENTED = [
        '/api/admin/actions',
        '/api/admin/audit-log',
        '/api/admin/club-requests',
        '/api/admin/club-requests/{id}/decision',
        '/api/admin/clubs/{clubId}/actions/{key}',
        '/api/admin/freshness',
        '/api/admin/messenger/failed',
        '/api/admin/pending-memberships',
        '/api/admin/pending-memberships/{id}/activate',
        '/api/admin/system-errors',
        '/api/club-approvals/{token}',
        '/api/coach-wishes/public/{token}',
        '/api/ffbb-logos/{scope}/{code}',
        '/api/ffbb/salles',
        '/api/ffbb/salles-proches',
    ];

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
