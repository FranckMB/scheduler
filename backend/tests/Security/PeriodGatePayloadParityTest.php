<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\VenuePeriodMode;
use App\Service\PeriodConstraintSelector;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P2-14 (axe constraint semantics §7.1) — LA PARITÉ GATE ↔ PAYLOAD : le récap valide
 * exactement le jeu de contraintes que le solveur recevra, ni plus ni moins. Avant P2-14,
 * la sélection vivait en DEUX exemplaires entretenus à la main (« miroir EXACT » assumé
 * en commentaire) — et deux divergences réelles existaient déjà :
 *
 * - une contrainte DATÉE visant une équipe désactivée restait validée par le gate alors
 *   que le payload la filtrait — le récap pouvait donc bloquer (ou avertir) sur une règle
 *   que le solveur ne verrait jamais ;
 * - une CLUB+tag HARD à gymnase dédié dont toutes les équipes taguées sont en pause était
 *   sortie du gate, alors que le payload émet encore ses lignes « interdit hors tag ».
 *
 * Les deux sont épinglées ici, ainsi que l'invariant central : chaque id de contrainte du
 * payload remonte à une entité que le gate a validée, et réciproquement. Si quelqu'un
 * ré-introduit une sélection locale d'un côté, ce test rougit.
 */
#[Group('phase1')]
#[Group('security')]
final class PeriodGatePayloadParityTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testGateAndPayloadAgreeOnTheConstraintSet(): void
    {
        [$user, $club, $season, $entry, $planId, $ids] = $this->seedScenario();

        // 1) LA sélection (source unique) retient exactement : la TEAM active, la CLUB+tag
        //    HARD à gymnase dédié (toutes taguées en pause — divergence n° 2 alignée), et
        //    la datée de l'équipe active. Tout le reste sort, chacun pour sa raison.
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $keptIds = array_map(static fn (Constraint $c): string => $c->getId(), $selection->kept);
        sort($keptIds);
        $expectedKept = [$ids['teamOk'], $ids['tagHardVenue'], $ids['datedOk']];
        sort($expectedKept);
        self::assertSame($expectedKept, $keptIds, 'la sélection retient le jeu attendu');
        $droppedVenueIds = array_map(static fn (array $d): string => $d['constraint']->getId(), $selection->droppedForDisabledVenue);
        sort($droppedVenueIds);
        $expectedDroppedVenue = [$ids['prefDisabledVenue'], $ids['tagAllActive']];
        sort($expectedDroppedVenue);
        self::assertSame($expectedDroppedVenue, $droppedVenueIds, 'les contraintes visant le gymnase désactivé sortent AVEC leur raison — y compris la CLUB+tag qui couvre toutes les actives (zéro ligne possible, revue #340 round 2)');
        // KEEP ne veut pas dire INTACTE : tagHardVenue est gardée pour son exclusivité de
        // gymnase dédié, mais sa clé secondaire désactivée tue ses règles par équipe — annoncé.
        self::assertSame(
            [$ids['tagHardVenue']],
            array_map(static fn (array $d): string => $d['constraint']->getId(), $selection->partiallyAppliedForDisabledVenue),
            'une CLUB+tag gardée pour sa seule exclusivité est signalée PARTIELLE (revue #340 round 2)',
        );

        // 2) PARITÉ : chaque ligne payload remonte à une entité retenue, et chaque entité
        //    retenue produit au moins une ligne. C'est l'invariant que les deux copies
        //    manuelles ne pouvaient que promettre.
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);
        $payloadRootIds = [];
        foreach ($payload['constraints'] as $row) {
            self::assertIsArray($row);
            $rootId = explode(':', (string) $row['id'])[0];
            // Le builder SYNTHÉTISE aussi des lignes qui ne viennent d'aucune entité
            // (« priority-tier » : les poids de rang) — le gestionnaire ne les a pas
            // saisies, le gate n'a donc rien à en valider. La parité porte sur les
            // contraintes-entités : on ne garde que les racines en UUID.
            if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rootId)) {
                $payloadRootIds[$rootId] = true;
            }
        }
        $payloadRootIds = array_keys($payloadRootIds);
        sort($payloadRootIds);
        self::assertSame($expectedKept, $payloadRootIds, 'le payload sérialise EXACTEMENT les entités que le gate a validées');

        // Blindage du filtre UUID (revue #340 round 1) : quel que soit le FORMAT d'id d'une
        // future voie d'expansion, aucune ligne du payload ne doit CONTENIR l'id d'une
        // entité sortie de la sélection — sinon le filtre par racine masquerait la fuite.
        $excludedIds = [$ids['teamDeactivated'], $ids['prefDisabledVenue'], $ids['facilityDefault'], $ids['tagAllActive'], $ids['datedInertTag'], $ids['datedDeactivated']];
        foreach ($payload['constraints'] as $row) {
            self::assertIsArray($row);
            foreach ($excludedIds as $excludedId) {
                self::assertStringNotContainsString($excludedId, (string) $row['id'], 'une entité sortie de la sélection ne produit AUCUNE ligne, sous aucun format d\'id');
            }
        }

        // 3) Le GATE HTTP consomme la même sélection : l'erreur de la datée sur équipe
        //    désactivée (config invalide À DESSEIN) ne remonte pas — elle ne partira pas au
        //    solveur ; et le gymnase désactivé est annoncé, pas passé sous silence.
        $this->client->request('POST', '/api/constraints/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($body['valid'], 'aucune erreur sur le jeu qui part réellement au solveur');
        self::assertArrayNotHasKey($ids['datedDeactivated'], $body['errors'], 'la datée d\'une équipe désactivée ne part pas au solveur : son invalidité ne bloque pas le récap (divergence n° 1 alignée)');
        // Ce test porte sur la PARITÉ gate↔payload, pas sur la capacité : on vérifie que les
        // warnings de SÉLECTION sont là, sans compter ceux des autres volets (revue #341 —
        // un décompte exact le rendait rouge à chaque évolution du volet capacité, pour une
        // raison étrangère à son sujet). Le volet capacité a son propre NR.
        $allWarnings = implode(' | ', array_map(strval(...), $body['warnings']));
        self::assertStringContainsString('Gymnase fermé pour période', $allWarnings, 'le drop pour gymnase désactivé est ANNONCÉ');
        self::assertStringContainsString('ne vise plus aucune équipe active', $allWarnings, 'la DATÉE au tag inerte est ANNONCÉE, jamais évaporée (revue #340)');
        self::assertStringContainsString('ses règles par équipe ne seront pas appliquées', $allWarnings, 'une CLUB+tag gardée pour sa seule exclusivité est annoncée PARTIELLE (revue #340 round 2)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season, 3: CalendarEntry, 4: string, 5: array<string, string>}
     */
    private function seedScenario(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club parité ' . $uid);
        $club->setSlug('parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PAR' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('parity-' . $uid . '@test.com');
        $user->setFirstName('Pa');
        $user->setLastName('Rité');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('parity-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        $teamActive = $this->team($club, $season, $category, 'Équipe active');
        $teamPaused = $this->team($club, $season, $category, 'Équipe en pause');

        $venueOpen = $this->venue($club, $season, 'Gymnase ouvert');
        $venueDisabled = $this->venue($club, $season, 'Gymnase fermé pour période');

        // Le tag et son assignation : SEULE l'équipe en pause est taguée — le cas exact de
        // la divergence n° 2 (CLUB+tag HARD à gymnase dédié, toutes taguées en pause).
        $tag = new TeamTag;
        $tag->setClubId($club->getId());
        $tag->setName('PARITE');
        $this->em->persist($tag);
        $this->em->flush();
        $assignment = new TeamTagAssignment;
        // BCK-11 : la table est tenant + RLS — une ligne sans club_id est refusée
        // par PostgreSQL, plus seulement invisible.
        $assignment->setClubId($club->getId());
        $assignment->setTeamId($teamPaused->getId());
        $assignment->setTagId($tag->getId());
        $assignment->setSeasonId($season->getId());
        $this->em->persist($assignment);

        // Second tag : il couvre TOUTES les équipes actives (la seule active du club).
        // Le cas revue #340 round 2 : sans équipe active HORS du tag, les lignes
        // « interdit hors tag » n'existent pas — l'entité ne doit PAS être gardée.
        $allTag = new TeamTag;
        $allTag->setClubId($club->getId());
        $allTag->setName('TOUTES');
        $this->em->persist($allTag);
        $this->em->flush();
        $allAssignment = new TeamTagAssignment;
        $allAssignment->setClubId($club->getId());
        $allAssignment->setTeamId($teamActive->getId());
        $allAssignment->setTagId($allTag->getId());
        $allAssignment->setSeasonId($season->getId());
        $this->em->persist($allAssignment);

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Reprise parité');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $planId = $this->planIdOf($entry);

        // Réglages de période : l'équipe en pause, le gymnase désactivé.
        $teamOverride = new TeamPeriodOverride;
        $teamOverride->setClubId($club->getId());
        $teamOverride->setSeasonId($season->getId());
        $teamOverride->setSchedulePlanId($planId);
        $teamOverride->setTeamId($teamPaused->getId());
        $teamOverride->setIsActive(false);
        $this->em->persist($teamOverride);

        $venueOverride = new VenuePeriodOverride;
        $venueOverride->setClubId($club->getId());
        $venueOverride->setSeasonId($season->getId());
        $venueOverride->setSchedulePlanId($planId);
        $venueOverride->setVenueId($venueDisabled->getId());
        $venueOverride->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($venueOverride);
        $this->em->flush();

        $ids = [
            // Gardée : TEAM valide sur l'équipe active.
            'teamOk' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamActive->getId(), ConstraintFamily::TIME, ['maxStartTime' => '20:00'], null)->getId(),
            // Sortie SANS bruit : TEAM sur l'équipe en pause.
            'teamDeactivated' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamPaused->getId(), ConstraintFamily::TIME, ['maxStartTime' => '20:00'], null)->getId(),
            // Sortie AVEC warning : elle nomme le gymnase désactivé (clé de config).
            'prefDisabledVenue' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamActive->getId(), ConstraintFamily::FACILITY, ['preferredVenueId' => $venueDisabled->getId()], null)->getId(),
            // Sortie par le DÉFAUT reprise (FACILITY droppée sans override).
            'facilityDefault' => $this->constraint($club, $season, ConstraintScope::FACILITY, $venueOpen->getId(), ConstraintFamily::FACILITY_CAPACITY, ['maxTeams' => 1], null)->getId(),
            // GARDÉE malgré « toutes taguées en pause » : HARD + gymnase dédié émet encore
            // ses lignes « interdit hors tag » (divergence n° 2 alignée).
            // ⚠ SEC-13 : ces deux-là portaient leurs clés de gymnase sur une famille
            // TIME. Le sélecteur de période les lit sans regarder la famille
            // (`PeriodConstraintSelector:238`), mais le MOTEUR exige
            // `family == "FACILITY"` : le mélange était inerte côté solveur, absent
            // de la vraie donnée (0 ligne), et la liste blanche le refuse désormais.
            // Famille corrigée ; ce que le test garde — la ligne dont la clé
            // secondaire vise un gymnase désactivé — est inchangé.
            // … y compris quand une clé SECONDAIRE vise le gymnase désactivé (revue #340
            // round 1) : les lignes « interdit hors tag » remplacent la config par le seul
            // gymnase DÉDIÉ — un drop entité aveugle les effaçait, le post-filtre par ligne
            // les préservait.
            'tagHardVenue' => $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ['targetTag' => 'PARITE', 'forcedVenueId' => $venueOpen->getId(), 'preferredVenueId' => $venueDisabled->getId()], null)->getId(),
            // Gardée : datée valide de l'équipe active.
            'datedOk' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamActive->getId(), ConstraintFamily::TIME, ['maxStartTime' => '21:00'], $entry->getId())->getId(),
            // Sortie AVEC warning gymnase (revue #340 round 2) : tag couvrant TOUTES les
            // actives (aucune ligne « interdit hors tag » possible) ET clé secondaire sur le
            // gymnase désactivé (les lignes par équipe meurent) → ZÉRO ligne, drop annoncé.
            'tagAllActive' => $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ['targetTag' => 'TOUTES', 'forcedVenueId' => $venueOpen->getId(), 'minAtVenueId' => $venueDisabled->getId()], null)->getId(),
            // Sortie AVEC warning (revue #340 round 1) : DATÉE CLUB+tag dont le tag ne vise
            // plus aucune équipe active, sans gymnase dédié (PREFERRED) — un geste explicite
            // du gestionnaire pour la période ne s'évapore jamais en silence (#8).
            'datedInertTag' => $this->constraint($club, $season, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ['targetTag' => 'PARITE', 'maxStartTime' => '18:00'], $entry->getId(), ConstraintRuleType::PREFERRED)->getId(),
            // Sortie (divergence n° 1 alignée) : datée de l'équipe EN PAUSE, config invalide
            // À DESSEIN (famille TIME sans clé) — si le gate la validait encore, son erreur
            // ferait échouer le récap sur une règle que le solveur ne verra jamais.
            'datedDeactivated' => $this->constraint($club, $season, ConstraintScope::TEAM, $teamPaused->getId(), ConstraintFamily::TIME, [], $entry->getId())->getId(),
        ];
        $this->em->flush();

        return [$user, $club, $season, $entry, $planId, $ids];
    }

    private function team(Club $club, Season $season, SportCategory $category, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function constraint(Club $club, Season $season, ConstraintScope $scope, ?string $target, ConstraintFamily $family, array $config, ?string $calendarEntryId, ConstraintRuleType $ruleType = ConstraintRuleType::HARD): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setScope($scope);
        $constraint->setScopeTargetId($target);
        $constraint->setFamily($family);
        $constraint->setRuleType($ruleType);
        $constraint->setName('Parité ' . $family->value . ' ' . uniqid());
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $constraint->setSortOrder(0);
        $constraint->setCalendarEntryId($calendarEntryId);
        $this->em->persist($constraint);

        return $constraint;
    }
}
