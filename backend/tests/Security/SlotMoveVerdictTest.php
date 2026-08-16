<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Exception\ScheduleGenerationInProgressException;
use App\Service\ClubGenerationLock;
use App\Service\EngineClient;
use App\Service\MoveSlotService;
use App\Service\RequestIdContext;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR — P2-2 F2b, axes *planning lifecycle* ET *constraint semantics* : LE DÉPLACEMENT D'UN
 * CRÉNEAU PASSE SOUS LE VERDICT DU MOTEUR, et le planning n'est ÉCRIT QUE SI le moteur dit
 * « oui ».
 *
 * Le geste passait par `manual-edit/one-time`, qui n'inspecte que les chevauchements bruts :
 * un créneau illégal (capacité, fenêtre, repos coach…) s'écrivait sans obstacle. F2b répare
 * ce danger livré — le backend HONORE le verdict.
 *
 * Ce qui est gardé ici (le moteur est MOQUÉ : on teste que le BACKEND respecte le verdict, pas
 * que le moteur sait refuser — ça, c'est le contrat cross-stack `ValidateAssignmentsContractSchemaTest`
 * + le smoke sémantique) :
 *  1. verdict « non » → le déplacement N'EST PAS écrit (état en base VÉRIFIÉ APRÈS, pas juste
 *     le retour) — falsification n°1 : faire écrire sans consulter le moteur rougit ici ;
 *  2. verdict « oui » → le créneau bouge ET le marqueur « retouché à la main » est posé ;
 *  3. la baseline envoyée au moteur NE CONTIENT PAS la source — sinon l'équipe se heurte à
 *     elle-même et un déplacement légal serait refusé (falsification n°2) ;
 *  4. une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé (falsification n°3).
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotMoveVerdictTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private const string CONFLICTING_TEAM_ID = '99999999-9999-4999-8999-999999999999';

    private const string COACH_ID = '88888888-8888-4888-8888-888888888888';

    private const string REFUSE = '{"valid":false,"violations":[{"rule":"coach_double_booking","message":"le coach Dupont a déjà les U15 à 20h dans un autre gymnase.","coachId":"88888888-8888-4888-8888-888888888888","dayOfWeek":4,"startTime":"20:00","conflictingTeamId":"99999999-9999-4999-8999-999999999999"}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private EntityManagerInterface $em;

    /** Verdict « non » : le créneau ne bouge PAS, et le marqueur reste à false. */
    public function testRefusedMoveIsNotWritten(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];
        $originalDay = $slot->getDayOfWeek();
        $originalVenue = $slot->getVenueId();
        $originalStart = $slot->getStartTime()->format('H:i');

        $service = $this->service(new MockHttpClient(new MockResponse(self::REFUSE, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertFalse($result['valid']);
        self::assertNotEmpty($result['violations']);
        self::assertSame('coach_double_booking', $result['violations'][0]['rule']);
        // Les ids du verdict transitent vers l'UI (surlignage du conflit) — tels que le moteur
        // les émet. conflictingTeamId nomme l'équipe DÉJÀ en place qui bloque le candidat.
        self::assertSame(self::CONFLICTING_TEAM_ID, $result['violations'][0]['conflictingTeamId']);
        self::assertSame(self::COACH_ID, $result['violations'][0]['coachId']);
        self::assertSame(4, $result['violations'][0]['dayOfWeek']);
        self::assertSame('20:00', $result['violations'][0]['startTime']);
        // Absents du verdict → null-safe, jamais une clé manquante.
        self::assertNull($result['violations'][0]['teamId']);
        self::assertNull($result['violations'][0]['venueId']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame($originalDay, $reloaded->getDayOfWeek(), 'un déplacement refusé ne doit PAS déplacer le créneau');
        self::assertSame($originalVenue, $reloaded->getVenueId());
        self::assertSame($originalStart, $reloaded->getStartTime()->format('H:i'));

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration(), 'un refus ne marque pas le planning comme retouché');
    }

    /** Verdict « oui » : le créneau bouge, et le score devient périmé (marqueur posé). */
    public function testAcceptedMoveIsWrittenAndMarksScoreStale(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])));
        $result = $service->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['violations']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
        self::assertSame(4, $reloaded->getDayOfWeek());
        self::assertSame('20:00', $reloaded->getStartTime()->format('H:i'));
        self::assertSame($ctx['venue2'], $reloaded->getVenueId());

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertTrue($schedule->isManuallyEditedSinceGeneration(), 'un déplacement accepté rend le score affiché périmé');
    }

    /** La baseline envoyée au moteur NE contient PAS la source (sinon l'équipe se heurte à elle-même). */
    public function testSourceIsRemovedFromTheBaseline(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];

        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);

        self::assertIsArray($captured);
        self::assertArrayHasKey('candidate', $captured);
        self::assertSame($slot->getTeamId(), $captured['candidate']['teamId']);
        self::assertArrayHasKey('slotTemplates', $captured);
        $ids = array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $captured['slotTemplates']);
        self::assertNotContains($slot->getId(), $ids, 'la SOURCE doit être retirée de la baseline avant le verdict');
    }

    /** Une génération en cours → 409 (exception), et le moteur n'est jamais consulté. */
    public function testMoveDuringGenerationIsRejectedWithoutCallingTheEngine(): void
    {
        $ctx = $this->seed();
        $slot = $ctx['slot'];

        $lock = self::getContainer()->get(ClubGenerationLock::class);
        self::assertNotNull($lock->acquire($ctx['clubId'], 60), 'seed: le verrou de génération doit être acquis');

        // Un client qui ÉCHOUE le test s'il est appelé : la sonde isGenerating() doit court-circuiter avant.
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('le moteur ne doit PAS être appelé pendant une génération');
        });

        try {
            $this->service($client)->move($slot, 4, new DateTimeImmutable('20:00'), $ctx['venue2']);
            self::fail('un déplacement pendant une génération doit lever');
        } catch (ScheduleGenerationInProgressException) {
            // attendu
        }

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame($slot->getDayOfWeek(), $reloaded?->getDayOfWeek(), 'rien ne bouge pendant une génération');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(MockHttpClient $client): MoveSlotService
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        return new MoveSlotService(
            $this->em,
            $container->get(ClubGenerationLock::class),
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(SchedulePlanProvisioner::class),
            new EngineClient($client, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new NullLogger,
        );
    }

    /**
     * Un club/saison/plan SEASON, un planning terminé NON choisi (donc éditable), et un
     * créneau source (U13, mardi 18h). Un second gymnase sert de cible au déplacement.
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string, venue1: string, venue2: string, slot: ScheduleSlotTemplate}
     */
    private function seed(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('smv-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = [];
        foreach (['Gymnase Un', 'Gymnase Deux'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[] = $venue->getId();
        }

        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . $suffix)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U13')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())->setPriorityTierId($tier->getId())->setName('U13')->setSessionsPerWeek(2);
        $this->em->persist($team);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        // lot D : la version ne peut être flushée sans plan — linkSeededSchedule résout le plan SEASON.
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        $slot = (new ScheduleSlotTemplate)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venueIds[0])
            ->setDayOfWeek(2)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
            ->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $schedule->getId(),
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1], 'slot' => $slot,
        ];
    }
}
