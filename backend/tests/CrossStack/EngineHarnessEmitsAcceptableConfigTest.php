<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ConstraintConfigValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * AUD-ENG-29 — le harnais « contract-accurate » du moteur émettait un `config` que l'API refuse.
 *
 * `engine/tests/support/pipeline.py` se présente comme reproduisant la forme que le backend
 * sérialise. Il posait `config["coachId"]`, doublon du `scopeTargetId` supprimé par SEC-13 :
 * `ConstraintConfigValidator::errors()` refuse aujourd'hui **toute clé hors liste**, donc un
 * payload réel portant cette clé prendrait 422.
 *
 * ⚑ **Le coût n'est pas dans le solveur, il est dans la PREUVE.** Le moteur n'a jamais lu que
 * `scopeTargetId` pour cette famille : zéro effet sur le résultat. Mais des tests qui passent
 * sur un payload que l'API rejetterait ne démontrent pas ce qu'ils prétendent démontrer — ils
 * valident un contrat imaginaire. Une suite verte devient alors une preuve vide, et c'est
 * exactement le genre de dérive qui ne se voit qu'en audit.
 *
 * Le test est piloté par la **liste blanche du backend** (`engineKeysByFamily()`), jamais par
 * une copie : c'est le backend qui décide de ce qu'un `config` peut porter.
 *
 * ⚠ Portée volontairement limitée au HARNAIS. Les tests individuels du moteur émettent, eux,
 * des clés hors liste **à dessein** (`test_constraints.py:329` pose `dateStart` pour prouver
 * qu'une fermeture datée est ignorée) : les leur interdire casserait des tests négatifs
 * légitimes. C'est le fichier qui se dit fidèle au contrat qui doit l'être.
 */
#[Group('contract')]
final class EngineHarnessEmitsAcceptableConfigTest extends TestCase
{
    private const string HARNESS = __DIR__ . '/../../../engine/tests/support/pipeline.py';

    public function testTheHarnessNeverEmitsAKeyTheApiWouldRefuse(): void
    {
        $source = file_get_contents(self::HARNESS);
        self::assertIsString($source, \sprintf('Illisible : %s', self::HARNESS));

        $emitted = $this->configKeysEmittedBy($source);
        self::assertNotEmpty($emitted, 'Aucune clé de config trouvée dans le harnais — si sa forme a changé, mettre ce test à jour plutôt que le retirer.');

        $accepted = $this->keysTheApiAccepts();
        $refused = array_values(array_diff($emitted, $accepted));

        self::assertSame([], $refused, \sprintf(
            "Le harnais du moteur émet des clés de `config` que l'API refuserait : %s.\n"
            . "Clés acceptées (toutes familles confondues) : %s.\n\n"
            . "Un harnais qui se dit fidèle au contrat et produit un payload voué au 422 fait\n"
            . 'passer ses tests pour une preuve qu\'ils ne sont pas.',
            implode(', ', $refused),
            implode(', ', $accepted),
        ));
    }

    /**
     * Les clés que le harnais pose lui-même dans un `config` — `config["x"] = …` et les
     * littéraux `config… = {"x": …}`. Les `config` reçus en PARAMÈTRE viennent de l'appelant
     * (un test), pas du harnais : ils ne sont pas de son ressort.
     *
     * @return list<string>
     */
    private function configKeysEmittedBy(string $source): array
    {
        $keys = [];

        preg_match_all('/config\["([a-zA-Z_]+)"\]\s*=/', $source, $assigned);
        $keys = array_merge($keys, $assigned[1]);

        preg_match_all('/config[^=\n]*=\s*\{([^{}]*)\}/', $source, $literals);
        foreach ($literals[1] as $body) {
            preg_match_all('/"([a-zA-Z_]+)"\s*:/', $body, $inline);
            $keys = array_merge($keys, $inline[1]);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function keysTheApiAccepts(): array
    {
        $accepted = [];
        foreach (new ConstraintConfigValidator()->engineKeysByFamily() as $keys) {
            $accepted = array_merge($accepted, $keys);
        }

        $accepted = array_values(array_unique($accepted));
        sort($accepted);

        return $accepted;
    }
}
