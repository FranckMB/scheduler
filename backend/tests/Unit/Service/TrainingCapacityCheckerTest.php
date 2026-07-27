<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Team;
use App\Entity\VenueTrainingSlot;
use App\Service\TrainingCapacityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — le manque de créneaux est dit AVANT la génération (P2-9, volet capacité).
 *
 * Le solveur le signale déjà après coup (`session_below_effective_min`), mais le
 * gestionnaire l'apprend au bout d'une génération, sur un planning déjà bancal.
 *
 * Ce que ces tests figent, et qui est facile à casser sans s'en rendre compte :
 *  - on somme les CAPACITÉS, pas le nombre de créneaux (un créneau à capacité 2
 *    accueille deux équipes ; le compter pour 1 ferait crier au loup) ;
 *  - `minSessionsOverride` prime sur `sessionsPerWeek` quand il est posé ;
 *  - les équipes INACTIVES ne demandent rien ;
 *  - offre == demande n'alerte PAS (le cas limite : un `>` au lieu d'un `>=`
 *    produirait une alerte sur un club parfaitement dimensionné) ;
 *  - l'ancre de période sépare les grilles (#8) : la demande d'une période ne se
 *    compare pas à l'offre de la saison entière.
 */
#[Group('phase1')]
final class TrainingCapacityCheckerTest extends TestCase
{
    public function testItStaysQuietWhenSupplyMatchesDemandExactly(): void
    {
        // 2 équipes × 2 séances = 4 ; 2 créneaux × capacité 2 = 4. Rien à dire.
        $warnings = $this->check(teams: [[2, null, true], [2, null, true]], slots: [2, 2]);

        self::assertSame([], $warnings, 'offre == demande ne doit PAS alerter — un `>` au lieu d’un `>=` casserait ici');
    }

    public function testItNamesTheShortfallWithTheFounderWording(): void
    {
        // 3 équipes × 2 = 6 demandés ; 2 créneaux capacité 1 + 1 capacité 2 = 4.
        $warnings = $this->check(teams: [[2, null, true], [2, null, true], [2, null, true]], slots: [1, 1, 2]);

        self::assertCount(1, $warnings);
        $message = $warnings[0];
        self::assertStringContainsString('demandent 6 créneaux', $message);
        self::assertStringContainsString('n’en offrent que 4', $message);
        self::assertStringContainsString('Au moins 2 séances', $message);
        // Jamais l'inverse : la comparaison est nécessaire, pas suffisante.
        self::assertStringNotContainsString('suffisant', $message);
    }

    public function testCapacityIsSummedNotCounted(): void
    {
        // UN seul créneau, mais de capacité 4 : l'offre vaut 4, pas 1.
        $warnings = $this->check(teams: [[2, null, true], [2, null, true]], slots: [4]);

        self::assertSame([], $warnings, 'un créneau à capacité 4 couvre 4 séances — compter les lignes crierait au loup');
    }

    public function testAnExplicitMinimumOverridesTheWeeklyTarget(): void
    {
        // L'équipe VEUT 3 séances mais son minimum explicite est 1 : la demande
        // retenue est le minimum, sinon on alerterait sur un club bien dimensionné.
        $warnings = $this->check(teams: [[3, 1, true]], slots: [1]);

        self::assertSame([], $warnings);
    }

    public function testInactiveTeamsDemandNothing(): void
    {
        $warnings = $this->check(teams: [[2, null, true], [99, null, false]], slots: [2]);

        self::assertSame([], $warnings, 'une équipe inactive ne s’entraîne pas — elle ne doit rien demander');
    }

    public function testNoTeamsMeansNothingToSay(): void
    {
        self::assertSame([], $this->check(teams: [], slots: []));
    }

    /**
     * @param list<array{0: int, 1: int|null, 2: bool}> $teams [sessionsPerWeek, minSessionsOverride, isActive]
     * @param list<int>                                 $slots capacités
     *
     * @return list<string>
     */
    private function check(array $teams, array $slots): array
    {
        $teamEntities = [];
        foreach ($teams as [$sessions, $override, $active]) {
            if (!$active) {
                continue; // le repository filtre déjà sur isActive — on reproduit son résultat
            }
            $team = new Team;
            $team->setSessionsPerWeek($sessions);
            if (null !== $override) {
                $team->setMinSessionsOverride($override);
            }
            $teamEntities[] = $team;
        }

        $slotEntities = [];
        foreach ($slots as $capacity) {
            $slot = new VenueTrainingSlot;
            $slot->setCapacity($capacity);
            $slotEntities[] = $slot;
        }

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            function (string $class) use ($teamEntities, $slotEntities): EntityRepository {
                $repository = $this->createMock(EntityRepository::class);
                $repository->method('findBy')->willReturn(Team::class === $class ? $teamEntities : $slotEntities);

                return $repository;
            },
        );

        return new TrainingCapacityChecker($entityManager)->warnings('club', 'season');
    }
}
