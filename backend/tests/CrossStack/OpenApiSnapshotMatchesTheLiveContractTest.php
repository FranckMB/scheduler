<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AUD-FRT-19 — le snapshot OpenAPI ne peut plus prendre du retard sur le vrai contrat.
 *
 * Le frontend écrit ses types À LA MAIN (`wizard/api.ts` 446 l., `matches/api.ts` 550 l.).
 * Une dérive back↔front est donc **invisible au typecheck** : TypeScript vérifie que le
 * code respecte l'interface écrite, jamais que l'interface décrit l'API. Le filet réel,
 * ce sont la normalisation défensive et les e2e — c'est-à-dire l'exécution, tard.
 *
 * L'audit proposait deux voies : un codegen (ambitieux, touche toutes les features) ou ce
 * test de dérive, « beaucoup moins cher ». C'est la seconde — avec ce qu'elle ne fait pas
 * dit franchement plus bas.
 *
 * ⚑ **Ce que ce test change.** `specs/courantes/openapi-snapshot.json` était un document :
 * régénéré à la main, daté à la main, cru sur parole. Il devient un ARTEFACT GARDÉ. Une
 * route ajoutée, un champ renommé, une réponse qui change de forme font rougir la CI au
 * lieu d'attendre qu'un humain pense à relancer `api:openapi:export`.
 *
 * ⚠ **Ce que ce test ne fait PAS** : il ne regarde pas les types TypeScript. Il ne peut
 * donc pas dire « le front a raté ce changement » — il dit « le contrat a bougé ». C'est
 * strictement moins que le codegen, et c'est assumé : rendre le mouvement VISIBLE est la
 * moitié du problème, et celle qui coûte trois ordres de grandeur de moins.
 *
 * ⚠ La comparaison porte sur la **surface** (chemins, méthodes, noms de schémas,
 * propriétés), pas sur le JSON octet à octet : une égalité stricte rougirait sur un ordre
 * de clés ou un numéro de version, et un test qui crie pour rien se fait désarmer.
 */
#[Group('contract')]
final class OpenApiSnapshotMatchesTheLiveContractTest extends KernelTestCase
{
    private const string SNAPSHOT = __DIR__ . '/../../../specs/courantes/openapi-snapshot.json';

    private const string REGENERATE = 'Régénérer : `docker compose exec php-fpm php bin/console api:openapi:export --output=/app/specs/courantes/openapi-snapshot.json`, puis mettre à jour `openapi-snapshot.meta.md` (ce qui a bougé, et pourquoi).';

    public function testEveryRouteOfTheLiveApiIsInTheSnapshot(): void
    {
        [$live, $snapshot] = $this->routeSurfaces();

        $missing = array_values(array_diff($live, $snapshot));
        $stale = array_values(array_diff($snapshot, $live));

        self::assertSame([], $missing, \sprintf(
            "Le contrat vivant expose des routes que le snapshot ignore :\n  - %s\n\n"
            . "Le frontend écrit ses types à la main À PARTIR de ce snapshot : ce qu'il ne\n"
            . "montre pas n'existe pour personne, jusqu'à ce qu'un e2e le trouve.\n%s",
            implode("\n  - ", $missing),
            self::REGENERATE,
        ));

        self::assertSame([], $stale, \sprintf(
            "Le snapshot annonce des routes que l'API n'expose plus :\n  - %s\n\n"
            . "C'est le sens le plus coûteux : un développeur front écrit du code contre une\n"
            . "route morte, et ne l'apprend qu'au 404 — en exécution, chez un gestionnaire.\n%s",
            implode("\n  - ", $stale),
            self::REGENERATE,
        ));
    }

    public function testEverySchemaOfTheLiveApiIsInTheSnapshot(): void
    {
        [$live, $snapshot] = $this->schemaNames();

        $missing = array_values(array_diff($live, $snapshot));
        $stale = array_values(array_diff($snapshot, $live));

        self::assertSame([], $missing, \sprintf(
            "Des schémas du contrat vivant manquent au snapshot : %s.\n%s",
            implode(', ', \array_slice($missing, 0, 12)),
            self::REGENERATE,
        ));
        self::assertSame([], $stale, \sprintf(
            "Le snapshot garde des schémas que l'API n'expose plus : %s.\n%s",
            implode(', ', \array_slice($stale, 0, 12)),
            self::REGENERATE,
        ));
    }

    /**
     * Le sens qui mord vraiment : un CHAMP renommé ou disparu. La route existe encore, le
     * schéma aussi, et l'interface TypeScript écrite à la main continue de compiler en
     * lisant une propriété que le serveur n'envoie plus — `undefined` silencieux jusqu'à
     * l'écran.
     */
    public function testNoPropertyDisappearedFromASharedSchema(): void
    {
        $live = $this->propertiesBySchema($this->liveSchemas());
        $snapshot = $this->propertiesBySchema($this->snapshotSchemas());

        $drifted = [];
        foreach ($snapshot as $schema => $properties) {
            if (!isset($live[$schema])) {
                continue; // le schéma entier a disparu : dit par le test précédent
            }
            foreach (array_diff($properties, $live[$schema]) as $property) {
                $drifted[] = \sprintf('%s.%s', $schema, $property);
            }
        }

        self::assertSame([], $drifted, \sprintf(
            "Des propriétés annoncées par le snapshot n'existent plus dans le contrat vivant :\n  - %s\n\n"
            . "TypeScript ne peut pas le voir : il vérifie que le code respecte l'interface\n"
            . "écrite à la main, jamais que cette interface décrit l'API. Le champ devient\n"
            . "`undefined` en silence, et l'écran affiche du vide.\n%s",
            implode("\n  - ", \array_slice($drifted, 0, 20)),
            self::REGENERATE,
        ));
    }

    /** @return array{list<string>, list<string>} */
    private function routeSurfaces(): array
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        \assert($factory instanceof OpenApiFactoryInterface);

        $live = [];
        foreach ($factory()->getPaths()->getPaths() as $path => $item) {
            foreach (['get', 'put', 'post', 'patch', 'delete'] as $method) {
                if (null !== $item->{'get' . ucfirst($method)}()) {
                    $live[] = \sprintf('%s %s', strtoupper($method), $path);
                }
            }
        }

        $snapshot = [];
        foreach ($this->snapshot()['paths'] as $path => $operations) {
            foreach (array_keys((array) $operations) as $method) {
                if (\in_array($method, ['get', 'put', 'post', 'patch', 'delete'], true)) {
                    $snapshot[] = \sprintf('%s %s', strtoupper((string) $method), $path);
                }
            }
        }

        sort($live);
        sort($snapshot);

        return [$live, $snapshot];
    }

    /** @return array{list<string>, list<string>} */
    private function schemaNames(): array
    {
        $live = array_keys($this->liveSchemas());
        $snapshot = array_keys($this->snapshotSchemas());
        sort($live);
        sort($snapshot);

        return [$live, $snapshot];
    }

    /**
     * @param array<string, mixed> $schemas
     *
     * @return array<string, list<string>>
     */
    private function propertiesBySchema(array $schemas): array
    {
        $out = [];
        foreach ($schemas as $name => $schema) {
            if (!\is_array($schema) || !isset($schema['properties']) || !\is_array($schema['properties'])) {
                continue;
            }
            $keys = array_map(strval(...), array_keys($schema['properties']));
            sort($keys);
            $out[(string) $name] = $keys;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function liveSchemas(): array
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        \assert($factory instanceof OpenApiFactoryInterface);

        $schemas = $factory()->getComponents()->getSchemas();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($schemas, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function snapshotSchemas(): array
    {
        /** @var array<string, mixed> $schemas */
        $schemas = $this->snapshot()['components']['schemas'] ?? [];

        return $schemas;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $raw = file_get_contents(self::SNAPSHOT);
        self::assertIsString($raw, \sprintf('Illisible : %s', self::SNAPSHOT));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
