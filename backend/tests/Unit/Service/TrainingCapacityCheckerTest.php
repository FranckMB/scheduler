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
 * Ce que ces tests figent :
 *  - on somme les CAPACITÉS, pas le nombre de créneaux (un créneau à capacité 2
 *    accueille deux équipes ; le compter pour 1 ferait crier au loup) ;
 *  - la demande est `sessionsPerWeek` — ce que l'équipe VEUT. Retenir un
 *    éventuel plancher sous-estimerait le besoin et masquerait un vrai manque ;
 *  - offre == demande n'alerte PAS (un `>` au lieu d'un `>=` alerterait sur un
 *    club parfaitement dimensionné) ;
 *  - **le silence volontaire sur les PÉRIODES** : leur offre et leur demande
 *    sont filtrées par `buildForOverlay` (gymnases désactivés, jours fermés,
 *    équipes désactivées, séances de période). Recopier ces règles ici les
 *    ferait dériver — la première version surestimait offre ET demande à la
 *    fois. Mieux vaut se taire que dire faux ;
 *  - les critères passés à `findBy` sont VÉRIFIÉS (club, saison, `isActive`,
 *    ancre de plan). Un mock qui renvoie une liste fixe quels que soient les
 *    critères ne garderait aucun de ces filtres : retirer `isActive` du code de
 *    production laisserait le test vert.
 */
#[Group('phase1')]
final class TrainingCapacityCheckerTest extends TestCase
{
    /** @var list<array{class: string, criteria: array<string, mixed>}> */
    private array $queries = [];

    public function testItStaysQuietWhenSupplyMatchesDemandExactly(): void
    {
        $warnings = $this->check(teams: [2, 2], slots: [2, 2]);

        self::assertSame([], $warnings, 'offre == demande ne doit PAS alerter — un `>` au lieu d’un `>=` casserait ici');
    }

    public function testItNamesTheShortfallWithTheFounderWording(): void
    {
        // 3 équipes × 2 = 6 demandés ; capacités 1 + 1 + 2 = 4 offerts.
        $warnings = $this->check(teams: [2, 2, 2], slots: [1, 1, 2]);

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
        $warnings = $this->check(teams: [2, 2], slots: [4]);

        self::assertSame([], $warnings, 'un créneau à capacité 4 couvre 4 séances — compter les lignes crierait au loup');
    }

    public function testItQueriesOnlyActiveTeamsOfTheClubAndSeason(): void
    {
        $this->check(teams: [2], slots: [2]);

        $teamQuery = $this->queryFor(Team::class);
        self::assertSame(
            ['clubId' => 'club-1', 'seasonId' => 'season-1', 'isActive' => true],
            $teamQuery,
            'une équipe inactive ne s’entraîne pas : le filtre doit être DANS la requête, pas dans le test',
        );
    }

    public function testItQueriesTheSeasonGridNotAPeriodOne(): void
    {
        $this->check(teams: [2], slots: [2]);

        $slotQuery = $this->queryFor(VenueTrainingSlot::class);
        self::assertSame(
            ['clubId' => 'club-1', 'seasonId' => 'season-1', 'schedulePlanId' => null],
            $slotQuery,
            '`schedulePlanId => null` est la grille de SAISON — omettre l’ancre mélangerait les créneaux de période',
        );
    }

    public function testItSaysNothingAboutAPeriodAndQueriesNothingAtAll(): void
    {
        $warnings = $this->check(teams: [2, 2, 2, 2, 2], slots: [1], schedulePlanId: 'plan-1');

        self::assertSame([], $warnings, 'une période filtre offre ET demande — recopier ces règles ici les ferait dériver');
        self::assertSame([], $this->queries, 'et on ne va même pas les chercher : le silence est décidé en amont');
    }

    public function testDemandIsTheWeeklyTargetNotAnyLowerFloor(): void
    {
        // Une équipe VEUT 3 séances mais porte un plancher explicite à 1. Le solveur
        // place jusqu'à `sessionsPerWeek` : retenir le plancher sous-estimerait le
        // besoin de 2 séances et ferait taire une alerte pourtant justifiée.
        $team = new Team;
        $team->setSessionsPerWeek(3);
        $team->setMinSessionsOverride(1);

        $warnings = $this->checkWith([$team], [1]);

        self::assertCount(1, $warnings, 'la demande est la CIBLE hebdomadaire, pas un éventuel plancher');
        self::assertStringContainsString('demandent 3 créneaux', $warnings[0]);
    }

    public function testNoTeamsMeansNothingToSay(): void
    {
        self::assertSame([], $this->check(teams: [], slots: []));
    }

    /**
     * @param list<int> $teams sessionsPerWeek de chaque équipe ACTIVE
     * @param list<int> $slots capacités
     *
     * @return list<string>
     */
    private function check(array $teams, array $slots, ?string $schedulePlanId = null): array
    {
        return $this->checkWith(
            array_map(static function (int $sessions): Team {
                $team = new Team;
                $team->setSessionsPerWeek($sessions);

                return $team;
            }, $teams),
            $slots,
            $schedulePlanId,
        );
    }

    /**
     * @param list<Team> $teamEntities
     * @param list<int>  $slots        capacités
     *
     * @return list<string>
     */
    private function checkWith(array $teamEntities, array $slots, ?string $schedulePlanId = null): array
    {
        $this->queries = [];

        $slotEntities = array_map(static function (int $capacity): VenueTrainingSlot {
            $slot = new VenueTrainingSlot;
            $slot->setCapacity($capacity);

            return $slot;
        }, $slots);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            function (string $class) use ($teamEntities, $slotEntities): EntityRepository {
                $repository = $this->createMock(EntityRepository::class);
                // On ENREGISTRE les critères reçus : c'est le seul moyen de garder
                // les filtres du code de production plutôt que de les réimplémenter
                // dans le test, où ils passeraient toujours.
                $repository->method('findBy')->willReturnCallback(
                    function (array $criteria) use ($class, $teamEntities, $slotEntities): array {
                        $this->queries[] = ['class' => $class, 'criteria' => $criteria];

                        return Team::class === $class ? $teamEntities : $slotEntities;
                    },
                );

                return $repository;
            },
        );

        return new TrainingCapacityChecker($entityManager)->warnings('club-1', 'season-1', $schedulePlanId);
    }

    /** @return array<string, mixed> les critères passés à `findBy` pour cette entité */
    private function queryFor(string $class): array
    {
        foreach ($this->queries as $query) {
            if ($query['class'] === $class) {
                return $query['criteria'];
            }
        }

        self::fail(\sprintf('aucune requête sur %s — le service ne consulte pas ce qu’il prétend', $class));
    }
}
