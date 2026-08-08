<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ImplicitConstraintConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D-43 — les règles implicites sont déclarées DEUX fois, et rien ne les comparait vraiment.
 *
 * Un contrôle existe pourtant côté moteur (`POST /implicit-constraints`, 409 si désaccord) —
 * mais il n'est atteignable que par `app:constraint:export-implicit`, une commande présente
 * dans **aucun job CI ni cible Make** : elle ne s'exécute jamais. Et même appelée, elle ne
 * comparait que les **noms**.
 *
 * Ce que le silence a laissé passer, mesuré le 2026-08-08 :
 *  - `MIN_SESSIONS` était décrite « **gets at least** its effective minimum » côté backend —
 *    donc un PLANCHER DUR — alors que le solveur n'en fait qu'une CIBLE (bonus soft) depuis
 *    ENG-18. Le moteur avait été recalé, le backend non : deux descriptions contradictoires
 *    du même mécanisme, dans un document que le produit présente comme la règle du jeu ;
 *  - les deux versions annoncées différaient (backend `2.1`, moteur `2.0`).
 *
 * Ce test remplace un contrôle réseau qui ne tourne jamais par une comparaison de fichiers
 * qui tourne toujours : même patron que les autres gardes du dépôt (dériver et diffé, plutôt
 * que synchroniser à la main).
 */
#[Group('contract')]
final class ImplicitRulesMatchEngineTest extends TestCase
{
    private const string ENGINE_RULES = __DIR__ . '/../../../engine/implicit_rules.json';

    public function testBackendAndEngineDeclareTheSameRules(): void
    {
        self::assertSame($this->engineRules(), $this->backendRules(), <<<'TXT'
            Les règles implicites déclarées par le backend et par le moteur ne coïncident plus.

            Ce ne sont pas deux documentations : c'est ce que le produit ANNONCE au gestionnaire
            (backend) contre ce que le solveur FAIT (moteur). Une divergence de description est
            donc un mensonge produit, pas une coquille — c'est ainsi que MIN_SESSIONS a annoncé
            un plancher dur pendant que le solveur n'en faisait qu'une cible.

            Aligner `ImplicitConstraintConfig` et `engine/implicit_rules.json` — sur le
            COMPORTEMENT RÉEL du solveur, jamais sur l'intention.
            TXT);
    }

    public function testBothSidesAnnounceTheSameRulesetVersion(): void
    {
        /** @var array{version?: string} $engine */
        $engine = $this->engineFile();
        self::assertSame(
            $engine['version'] ?? null,
            ImplicitConstraintConfig::RULESET_VERSION,
            'Backend et moteur annoncent des versions de jeu de règles différentes — elles n\'ont jamais été comparées (le contrôle 409 ne regarde que les noms).',
        );
    }

    /**
     * Nom => [enabled, description], côté backend.
     *
     * @return array<string, array{bool, string}>
     */
    private function backendRules(): array
    {
        $rules = [];
        foreach (new ImplicitConstraintConfig()->getConfig() as $rule) {
            $rules[(string) $rule['type']] = [(bool) $rule['enabled'], (string) $rule['description']];
        }
        ksort($rules);

        return $rules;
    }

    /**
     * Idem, côté moteur.
     *
     * @return array<string, array{bool, string}>
     */
    private function engineRules(): array
    {
        $rules = [];
        /** @var list<array{name: string, enabled: bool, description: string}> $declared */
        $declared = $this->engineFile()['rules'] ?? [];
        foreach ($declared as $rule) {
            $rules[$rule['name']] = [$rule['enabled'], $rule['description']];
        }
        ksort($rules);

        self::assertNotEmpty($rules, 'engine/implicit_rules.json ne déclare plus aucune règle.');

        return $rules;
    }

    /** @return array<string, mixed> */
    private function engineFile(): array
    {
        $raw = file_get_contents(self::ENGINE_RULES);
        self::assertIsString($raw, 'engine/implicit_rules.json est illisible.');

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
