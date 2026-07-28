<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TrainingCapacityChecker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — le manque de créneaux est dit AVANT la génération (P2-9, volet capacité).
 *
 * Le solveur le signale déjà après coup (`session_below_effective_min`), mais le
 * gestionnaire l'apprend au bout d'une génération, sur un planning déjà bancal.
 *
 * ⚠ Ce que ces tests figent AVANT tout : le service lit le PAYLOAD que le builder
 * expédie au solveur, il ne recalcule rien. Deux versions successives ont dérivé
 * en réimplémentant les filtres du builder (bridage `canSplit` ignoré, filtre
 * `isActive` inventé, période non filtrée) et se trompaient dans les deux sens.
 * D'où des tests qui alimentent un payload, exactement comme la production.
 *
 * Le reste :
 *  - on somme les CAPACITÉS, pas le nombre de créneaux ;
 *  - offre == demande n'alerte PAS (un `>` au lieu d'un `>=` alerterait sur un
 *    club parfaitement dimensionné) ;
 *
 * Le silence sur les PÉRIODES vit chez l'appelant (le contrôleur n'appelle pas),
 * et c'est `ValidateConstraintsPeriodSilenceTest` qui le garde — décidé sur le
 * PÉRIMÈTRE et non sur l'identifiant de plan, une période jamais « Adaptée »
 * n'en ayant pas.
 */
#[Group('phase1')]
final class TrainingCapacityCheckerTest extends TestCase
{
    public function testItStaysQuietWhenSupplyMatchesDemandExactly(): void
    {
        $warnings = $this->check(sessions: [2, 2], capacities: [2, 2]);

        self::assertSame([], $warnings, 'offre == demande ne doit PAS alerter — un `>` au lieu d’un `>=` casserait ici');
    }

    public function testItNamesTheShortfallWithTheFounderWording(): void
    {
        // 3 équipes × 2 = 6 demandés ; capacités 1 + 1 + 2 = 4 offerts.
        $warnings = $this->check(sessions: [2, 2, 2], capacities: [1, 1, 2]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('demandent 6 créneaux', $warnings[0]);
        self::assertStringContainsString('n’en offrent que 4', $warnings[0]);
        self::assertStringContainsString('Au moins 2 séances', $warnings[0]);
        // La condition est nécessaire, pas suffisante : ne jamais promettre l'inverse.
        self::assertStringNotContainsString('suffisant', $warnings[0]);
    }

    public function testCapacityIsSummedNotCounted(): void
    {
        // UN seul créneau, mais de capacité 4 : l'offre vaut 4, pas 1.
        $warnings = $this->check(sessions: [2, 2], capacities: [4]);

        self::assertSame([], $warnings, 'un créneau à capacité 4 couvre 4 séances — compter les lignes crierait au loup');
    }

    public function testItReadsTheSolverPayloadSoTheCanSplitClampIsHonoured(): void
    {
        // Le builder BRIDE la capacité à 1 sur un gymnase non divisible. Le service
        // relit ces capacités déjà bridées : une version qui interrogeait les
        // entités comptait la capacité STOCKÉE (2) et annonçait des places que le
        // solveur ne verrait jamais.
        $warnings = $this->check(sessions: [2, 2], capacities: [1, 1]);

        self::assertCount(1, $warnings, 'les capacités du payload font foi, pas celles stockées en base');
        self::assertStringContainsString('n’en offrent que 2', $warnings[0]);
    }

    public function testNoTeamsMeansNothingToSay(): void
    {
        self::assertSame([], $this->check(sessions: [], capacities: []));
    }

    /**
     * @param list<int> $sessions   `sessionsPerWeek` de chaque équipe DU PAYLOAD
     * @param list<int> $capacities capacités des créneaux DU PAYLOAD
     *
     * @return list<string>
     */
    private function check(array $sessions, array $capacities): array
    {
        $payload = [
            'teams' => array_map(static fn (int $s): array => ['sessionsPerWeek' => $s], $sessions),
            // Un seul gymnase porteur de tous les créneaux : le service somme les
            // capacités de TOUS les gymnases, leur répartition n'entre pas en jeu.
            'venues' => [['trainingSlots' => array_map(static fn (int $c): array => ['capacity' => $c], $capacities)]],
        ];

        return new TrainingCapacityChecker()->warnings($payload);
    }
}
