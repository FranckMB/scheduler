<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ImplicitConstraintConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ImplicitConstraintConfigTest extends TestCase
{
    private ImplicitConstraintConfig $config;

    public function testGetConfigReturnsTwelveImplicitConstraints(): void
    {
        $result = $this->config->getConfig();

        // 6 règles du produit + 4 de bien-être + 2 extensions futures désactivées (D-43 étendu).
        self::assertCount(12, $result);
        self::assertArrayHasKey('venueAtMostOne', $result);
        self::assertArrayHasKey('coachNoOverlap', $result);
        self::assertArrayHasKey('coachPlayerNoOverlap', $result);
        self::assertArrayHasKey('teamNoOverlap', $result);
        self::assertArrayHasKey('minSessions', $result);
        self::assertArrayHasKey('oneSessionPerDay', $result);
        self::assertArrayHasKey('coachRestDay', $result);
        self::assertArrayHasKey('salarieDistribution', $result);
        self::assertArrayHasKey('maxConsecutiveSessions', $result);
        self::assertArrayHasKey('ageAscending', $result);
        self::assertArrayHasKey('travelFeasibility', $result);
        self::assertArrayHasKey('requiredBridge', $result);
    }

    public function testWellnessRulesCarryTheirFamilyAndDefaultIntensity(): void
    {
        $result = $this->config->getConfig();

        foreach (['coachRestDay', 'salarieDistribution', 'maxConsecutiveSessions', 'ageAscending'] as $key) {
            self::assertTrue($result[$key]['enabled'], $key . ' est active par défaut');
            self::assertSame('WELLNESS', $result[$key]['family'], $key . ' est une règle de bien-être');
            self::assertSame('HARD', $result[$key]['defaultIntensity'], $key . ' est dure par défaut (absence de réglage)');
        }
    }

    public function testFutureExtensionRulesAreDisabledAndAnnouncedAsSuch(): void
    {
        $result = $this->config->getConfig();

        foreach (['travelFeasibility', 'requiredBridge'] as $key) {
            self::assertFalse($result[$key]['enabled'], $key . ' est une extension future, désactivée');
            self::assertSame('DÉSACTIVÉE = extension future', $result[$key]['note']);
            self::assertStringContainsString('DISABLED (future extension)', $result[$key]['description']);
        }
    }

    public function testVenueAtMostOneConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('VENUE_AT_MOST_ONE', $result['venueAtMostOne']['type']);
        self::assertTrue($result['venueAtMostOne']['enabled']);
        // P4-65 — cette assertion figeait « max one team per time slot », soit une règle que le
        // moteur n'applique pas : il borne à la CAPACITÉ du créneau. L'assertion épingle
        // désormais ce qui porte le sens, pas la phrase entière — c'est cette description que
        // `POST /implicit-constraints` compare au moteur, et « max one team » y ferait croire
        // qu'un créneau ne peut jamais accueillir deux équipes.
        $description = $result['venueAtMostOne']['description'];
        self::assertStringContainsString('at most its slot capacity', $description);
        self::assertStringContainsString('default 1', $description);
    }

    public function testCoachNoOverlapConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('COACH_NO_OVERLAP', $result['coachNoOverlap']['type']);
        self::assertTrue($result['coachNoOverlap']['enabled']);

        // D-14 — cette assertion épinglait « max one team per time slot », soit la règle que
        // le MOTEUR appliquait et non celle que le produit veut : un coach PEUT tenir deux
        // équipes dans le même gymnase (il y surveille deux groupes), et c'est le moteur qui
        // a été aligné. La phrase décrit désormais l'exemption ET sa borne, parce que c'est
        // elle que `POST /implicit-constraints` compare au moteur — une formulation qui
        // n'énoncerait que « max one team » ferait croire à un dédoublement interdit.
        $description = $result['coachNoOverlap']['description'];
        self::assertStringContainsString('EXCEPT in the same venue', $description);
        self::assertStringContainsString('different venues remain impossible', $description);
    }

    public function testCoachPlayerNoOverlapConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('COACH_PLAYER_NO_OVERLAP', $result['coachPlayerNoOverlap']['type']);
        self::assertTrue($result['coachPlayerNoOverlap']['enabled']);
        self::assertSame('A coach-player cannot be in two roles simultaneously', $result['coachPlayerNoOverlap']['description']);
    }

    public function testTeamNoOverlapConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('TEAM_NO_OVERLAP', $result['teamNoOverlap']['type']);
        self::assertTrue($result['teamNoOverlap']['enabled']);
        self::assertSame('A team cannot have two sessions at the same time', $result['teamNoOverlap']['description']);
    }

    public function testMinSessionsConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('MIN_SESSIONS', $result['minSessions']['type']);
        self::assertTrue($result['minSessions']['enabled']);
        // D-43 — cette assertion épinglait « gets at least », donc un PLANCHER DUR, alors que
        // le solveur n'en fait qu'une CIBLE (bonus soft) depuis ENG-18 : elle verrouillait la
        // description mensongère et aurait fait rougir quiconque la corrigeait. La formulation
        // vient désormais du moteur (`engine/implicit_rules.json`), et `ImplicitRulesMatchEngineTest`
        // exige que les deux côtés la partagent — ici on garde seulement qu'elle dit « cible ».
        self::assertStringContainsString('TARGETS', $result['minSessions']['description']);
        self::assertStringContainsString('not a hard floor', $result['minSessions']['description']);
    }

    public function testGetConstraintsArrayReturnsTwelveIndexedEntries(): void
    {
        $result = $this->config->getConstraintsArray();

        self::assertCount(12, $result);
        self::assertSame('VENUE_AT_MOST_ONE', $result[0]['type']);
    }

    protected function setUp(): void
    {
        $this->config = new ImplicitConstraintConfig;
    }
}
