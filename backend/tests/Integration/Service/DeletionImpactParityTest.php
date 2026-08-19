<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Deletion\CascadePlan;
use App\Deletion\DeletionImpactCounter;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Fixture;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\EntityCascadeDeleter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — CE QU'ON ANNONCE == CE QU'ON DÉTRUIT (axes §7.1 : planning lifecycle,
 * périmètre engagé).
 *
 * P3-16. Avant ce lot, la modale de suppression comptait **côté front, depuis le cache
 * react-query** : elle annonçait 2 familles quand supprimer un gymnase en emportait 7 et en
 * dépointait 3 — dont les séances de TOUS les plannings (celui en vigueur compris) et la
 * salle de matchs déjà déclarés à la fédération (DOC-2). Le gestionnaire confirmait une
 * destruction qu'on ne lui avait jamais montrée.
 *
 * L'invariant gardé ici est la **maison unique** : `CascadePlan` est la seule liste, exécutée
 * par `EntityCascadeDeleter` et comptée par `DeletionImpactCounter`. Trois verrous :
 *
 *   1. **aucune étape muette par accident** — toute étape sans libellé doit figurer dans la
 *      liste FERMÉE ci-dessous, nommément. Ajouter une destruction sans son annonce rougit ;
 *      donner un libellé à une étape déclarée muette rougit aussi (falsifié dans les deux sens) ;
 *   2. **aucune destruction hors du plan** — les trois purges ne contiennent aucun DQL en
 *      propre : elles délèguent. Sans ce verrou, on pourrait re-glisser un `delete()` dans
 *      `EntityCascadeDeleter` et rouvrir exactement la dérive qu'on vient de fermer ;
 *   3. **l'annoncé se vérifie en base** — sur un gymnase réel, chaque ligne annoncée
 *      correspond à ce qui disparaît vraiment, et ce qui SURVIT (le match, dépointé) survit.
 */
#[Group('phase1')]
#[Group('integration')]
final class DeletionImpactParityTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /**
     * Les étapes délibérément SILENCIEUSES, par leur classe d'entité et leur champ.
     *
     * Un diagnostic est un constat du solveur sur une génération passée : il ne survit pas à
     * son sujet, et l'annoncer n'aiderait personne à décider — c'est du bruit dans une modale
     * qui doit se lire en trois secondes. Toute autre étape muette est un OUBLI.
     */
    private const SILENT_STEPS = ['ScheduleDiagnostic.teamId', 'ScheduleDiagnostic.venueId', 'ScheduleDiagnostic.coachId'];

    private EntityManagerInterface $em;

    public function testEveryDestructionIsAnnounced(): void
    {
        $silent = [];
        foreach (['team' => CascadePlan::forTeam(), 'venue' => CascadePlan::forVenue(), 'coach' => CascadePlan::forCoach(), 'slot' => CascadePlan::forSlot()] as $kind => $steps) {
            self::assertNotSame([], $steps, "le plan « {$kind} » ne peut pas être vide");
            foreach ($steps as $step) {
                if (null === $step->label()) {
                    $silent[] = $this->describe($step);
                }
            }
        }

        sort($silent);
        $expected = self::SILENT_STEPS;
        sort($expected);
        // Égalité STRICTE : une étape muette non listée est un oubli d'annonce ; une étape
        // listée qui a gagné un libellé doit sortir de la liste. Les deux sens rougissent.
        self::assertSame($expected, $silent, 'toute étape sans libellé doit être déclarée muette nommément');
    }

    public function testNoDestructionEscapesThePlan(): void
    {
        $source = file_get_contents(new ReflectionClass(EntityCascadeDeleter::class)->getFileName() ?: '');
        self::assertIsString($source);

        foreach (['purgeChildrenOfTeam', 'purgeChildrenOfVenue', 'purgeChildrenOfCoach', 'purgeChildrenOfSlot'] as $method) {
            $body = $this->methodBody($source, $method);
            self::assertStringContainsString('CascadePlan::', $body, "{$method} doit déléguer au plan");
            // Le DQL en propre est précisément ce qui rouvrirait la dérive : une destruction
            // que le compteur ne voit pas.
            self::assertStringNotContainsString('createQueryBuilder', $body, "{$method} ne doit contenir aucun DQL propre — tout passe par le plan");
        }
    }

    public function testTheAnnouncedImpactMatchesWhatTheDeleteActuallyDoes(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $other = $this->venue($club, $season, 'Debarros');
        $team = $this->team($club, $season, forcedVenueId: $venue->getId());
        $schedule = $this->schedule($club, $season);

        $this->em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->persist((new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        // DOC-2 : un match DÉJÀ DÉCLARÉ à la fédération, posé dans ce gymnase.
        $declared = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adversaire')
            ->setStatus(FixtureStatus::SUBMITTED)->setVenueId($venue->getId());
        $this->em->persist($declared);
        // Un créneau du même club dans un AUTRE gymnase : il ne doit ni être compté ni partir.
        $this->em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($other->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forVenue($venue);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }

        self::assertSame(1, $announced['venue_slot'] ?? 0, 'le créneau de disponibilité est annoncé');
        self::assertSame(1, $announced['venue_reservation'] ?? 0, 'la réservation est annoncée');
        self::assertSame(1, $announced['venue_slot_template'] ?? 0, 'la séance placée est annoncée');
        self::assertSame(1, $announced['venue_forced_team'] ?? 0, 'l\'équipe qui perd son gymnase imposé est annoncée');
        self::assertSame(1, $announced['venue_fixture'] ?? 0, 'le match qui perd sa salle est annoncé');
        self::assertSame(1, $impact->declaredFixtures, 'DOC-2 : le match DÉJÀ DÉCLARÉ est compté à part');
        self::assertFalse($impact->blocked, 'un gymnase ne se refuse pas : la décision fondateur laisse le geste passer');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfVenue($venue);
        $this->em->flush();
        $this->em->clear();

        // Ce qui était annoncé a bien disparu…
        self::assertSame(0, $this->countBy(VenueTrainingSlot::class, 'venueId', $venue->getId()));
        self::assertSame(0, $this->countBy(Reservation::class, 'venueId', $venue->getId()));
        self::assertSame(0, $this->countBy(ScheduleSlotTemplate::class, 'venueId', $venue->getId()));
        self::assertNull($this->em->getRepository(Team::class)->find($team->getId())?->getForcedVenueId());
        // …et ce qui SURVIT survit : le match reste, sans sa salle (il redevient « à placer »,
        // donc récupérable — c'est ce qui justifie d'avertir plutôt que de refuser).
        $reloaded = $this->em->getRepository(Fixture::class)->find($declared->getId());
        self::assertNotNull($reloaded, 'un match déclaré ne disparaît pas avec le gymnase');
        self::assertNull($reloaded->getVenueId());
        // La frontière du gymnase tient : l'autre salle est intacte.
        self::assertSame(1, $this->countBy(VenueTrainingSlot::class, 'venueId', $other->getId()));
    }

    public function testASlotAnnouncesItsPinsAndNeverCrossesItsOwnLayer(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $team = $this->team($club, $season, forcedVenueId: $venue->getId());
        $schedule = $this->schedule($club, $season);
        $at = new DateTimeImmutable('18:00');

        $slot = (new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
        // Les DEUX faces d'un même épinglage : la réservation ET le verrou HARD qu'elle a
        // matérialisé. C'est le second que l'écran n'annonçait pas.
        $this->em->persist((new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90));
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)->setLockLevel(LockLevel::HARD));
        // Un placement que le SOLVEUR a choisi au même horaire : c'est un RÉSULTAT, il
        // appartient à sa version — ni annoncé, ni détruit.
        $solverPick = (new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)->setLockLevel(LockLevel::NONE);
        $this->em->persist($solverPick);
        // Une réservation d'une PÉRIODE au même triplet : le socle ne doit JAMAIS l'emporter
        // (invariant fondateur n°1 — une période ne modifie pas le planning principal, et
        // réciproquement).
        $periodPlan = (new SchedulePlan)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setType(SchedulePlanType::CLOSURE)->setName('Reprise')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-28'));
        $this->em->persist($periodPlan);
        $this->em->flush();
        $periodReservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)
            ->setSchedulePlanId($periodPlan->getId());
        $this->em->persist($periodReservation);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forSlot($slot);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }

        self::assertSame(1, $announced['slot_reservation'] ?? 0, 'la réservation de SA couche est annoncée, pas celle de la période');
        self::assertSame(1, $announced['slot_hard_lock'] ?? 0, 'le verrou HARD est annoncé — c’est ce que l’écran taisait');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfSlot($slot);
        $this->em->flush();
        $this->em->clear();

        // L'annoncé a disparu…
        self::assertSame(0, $this->countBaseReservations($venue->getId()), 'la réservation de SOCLE est partie');
        self::assertSame(0, $this->countHardLocks($venue->getId()));
        // …et rien d'autre : le placement du solveur et la réservation de la PÉRIODE survivent.
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($solverPick->getId()), 'un placement SOFT/NONE est un résultat, jamais un épinglage');
        self::assertNotNull($this->em->getRepository(Reservation::class)->find($periodReservation->getId()), 'la réservation d’une PÉRIODE ne part pas avec un créneau de saison');
    }

    /**
     * AUD-BCK-15 — la cascade d'une ÉQUIPE, EXÉCUTÉE en base, avec la seule règle métier qui
     * lui soit propre : le sort des groupes de mutualisation.
     *
     * Jusqu'ici `forTeam()` n'était vérifié qu'en STRUCTURE (annoncé == plan). C'est
     * insuffisant pour du code qui DÉTRUIT : la structure dit qu'une étape existe, pas
     * qu'elle fait ce qu'elle promet. Et `SharedTrainingGroupPruneStep` — « un groupe qui
     * tombe sous deux membres part avec ses lignes restantes » — n'avait AUCUN test : la
     * règle de survie d'un groupe mutualisé n'était vérifiée nulle part.
     *
     * Trois groupes, pour que la règle soit falsifiable dans les deux sens :
     *   - un DUO avec l'équipe supprimée → il tombe, et la ligne du survivant part avec ;
     *   - un TRIO avec l'équipe supprimée → il SURVIT, à deux membres exactement ;
     *   - un duo SANS elle → il ne bouge pas (la frontière tient).
     */
    public function testDeletingATeamPrunesItsSharedGroupsAndKeepsTheOthers(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $doomed = $this->team($club, $season, forcedVenueId: $venue->getId());
        $mate = $this->team($club, $season, forcedVenueId: $venue->getId());
        $third = $this->team($club, $season, forcedVenueId: $venue->getId());
        $stranger = $this->team($club, $season, forcedVenueId: $venue->getId());

        // Un duo : sans l'équipe supprimée, « s'entraîner ensemble » n'a plus de sens.
        $duo = $this->sharedGroup($club, $season, [$doomed, $mate]);
        // Un trio : il reste deux équipes, donc il garde son sens.
        $trio = $this->sharedGroup($club, $season, [$doomed, $mate, $third]);
        // Un duo étranger : l'équipe supprimée n'y est pas.
        $untouched = $this->sharedGroup($club, $season, [$mate, $stranger]);

        // Une réservation, pour vérifier au passage qu'une étape ordinaire du plan agit bien.
        $this->em->persist((new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($doomed->getId())->setVenueId($venue->getId())->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forTeam($doomed);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }

        // Le COMPTE annoncé est le nombre de groupes où l'équipe FIGURE — pas le nombre de
        // groupes qui vont tomber. C'est délibéré (cf. le docblock de l'étape) : annoncer la
        // survie exigerait de rejouer la règle dans le compteur, donc de la tenir à deux
        // endroits — précisément ce que le plan unique de P3-16 supprime.
        self::assertSame(2, $announced['team_shared_group'] ?? 0, 'les 2 groupes où elle figure sont annoncés, pas le 3e');
        self::assertSame(1, $announced['team_reservation'] ?? 0, 'la réservation est annoncée');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfTeam($doomed);
        $this->em->flush();
        $this->em->clear();

        // Le duo est parti — groupe ET lignes, y compris celle du membre survivant.
        self::assertNull($this->em->getRepository(SharedTrainingGroup::class)->find($duo), 'un duo amputé n\'a plus de sens : il part');
        self::assertSame(0, $this->countBy(SharedTrainingGroupTeam::class, 'groupId', $duo), 'ses lignes partent avec lui, pas seulement celle de l\'équipe supprimée');

        // Le trio survit, à DEUX membres : la règle est un seuil, pas une suppression en chaîne.
        self::assertNotNull($this->em->getRepository(SharedTrainingGroup::class)->find($trio), 'un trio amputé reste un groupe');
        self::assertSame(2, $this->countBy(SharedTrainingGroupTeam::class, 'groupId', $trio), 'il lui reste exactement ses deux autres équipes');

        // Le groupe étranger n'a pas bougé d'un pouce.
        self::assertNotNull($this->em->getRepository(SharedTrainingGroup::class)->find($untouched));
        self::assertSame(2, $this->countBy(SharedTrainingGroupTeam::class, 'groupId', $untouched), 'un groupe sans elle ne perd rien');

        // Et l'équipe supprimée ne figure plus dans AUCUN groupe.
        self::assertSame(0, $this->countBy(SharedTrainingGroupTeam::class, 'teamId', $doomed->getId()));
        self::assertSame(0, $this->countBy(Reservation::class, 'teamId', $doomed->getId()));
    }

    /**
     * AUD-BCK-15 — la cascade d'un COACH, exécutée en base.
     *
     * Son plan mêle deux gestes que la structure seule ne distingue pas : ce qui est
     * SUPPRIMÉ (le lien équipe-coach) et ce qui est DÉTACHÉ (la séance placée garde son
     * créneau, elle perd juste son coach ; la doléance survit à son auteur). Confondre les
     * deux détruirait un planning au lieu d'en retirer un nom.
     */
    public function testDeletingACoachDetachesRatherThanDestroysWhatMustSurvive(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $team = $this->team($club, $season, forcedVenueId: $venue->getId());
        $schedule = $this->schedule($club, $season);

        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setFirstName('Alex')->setLastName('Martin')->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();

        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN)->setIsRequired(true));
        $placed = (new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setCoachId($coach->getId());
        $this->em->persist($placed);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forCoach($coach);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }
        self::assertSame(1, $announced['coach_team'] ?? 0, 'le lien équipe-coach est annoncé');
        self::assertSame(1, $announced['coach_slot'] ?? 0, 'la séance qui perdra son coach est annoncée');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfCoach($coach);
        $this->em->flush();
        $this->em->clear();

        // SUPPRIMÉ : le lien n'a pas de sens sans son coach.
        self::assertSame(0, $this->countBy(TeamCoach::class, 'coachId', $coach->getId()));

        // DÉTACHÉ : la séance reste EXACTEMENT où elle est, sans son coach. C'est toute la
        // différence — supprimer ici trouerait le planning d'un club pour un départ de coach.
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($placed->getId());
        self::assertNotNull($reloaded, 'une séance placée ne disparaît pas avec son coach');
        self::assertNull($reloaded->getCoachId(), 'elle perd son coach, pas son créneau');
        self::assertSame(1, $reloaded->getDayOfWeek());
        self::assertSame($venue->getId(), $reloaded->getVenueId());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function describe(object $step): string
    {
        $reflection = new ReflectionClass($step);
        $class = $reflection->hasProperty('entityClass') ? (string) $reflection->getProperty('entityClass')->getValue($step) : $reflection->getShortName();
        $field = $reflection->hasProperty('field') ? (string) $reflection->getProperty('field')->getValue($step) : '?';
        $short = str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;

        return $short . '.' . $field;
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, "méthode {$method} introuvable");
        $open = strpos($source, '{', $start);
        self::assertIsInt($open);
        $depth = 0;
        for ($i = $open; $i < \strlen($source); ++$i) {
            $depth += '{' === $source[$i] ? 1 : ('}' === $source[$i] ? -1 : 0);
            if (0 === $depth) {
                return substr($source, $open, $i - $open + 1);
            }
        }
        self::fail("corps de {$method} non borné");
    }

    /** Les réservations de SOCLE (hors période) encore posées sur ce gymnase. */
    private function countBaseReservations(string $venueId): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(Reservation::class, 'e')
            ->where('e.venueId = :v')->andWhere('e.schedulePlanId IS NULL')
            ->setParameter('v', $venueId)
            ->getQuery()->getSingleScalarResult();
    }

    /** Les verrous HARD encore posés sur ce gymnase, toutes versions confondues. */
    private function countHardLocks(string $venueId): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(ScheduleSlotTemplate::class, 'e')
            ->where('e.venueId = :v')->andWhere('e.lockLevel = :hard')
            ->setParameter('v', $venueId)->setParameter('hard', LockLevel::HARD)
            ->getQuery()->getSingleScalarResult();
    }

    /** @param class-string $class */
    private function countBy(string $class, string $field, string $value): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from($class, 'e')
            ->where(\sprintf('e.%s = :v', $field))->setParameter('v', $value)->getQuery()->getSingleScalarResult();
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        return [$club, $season];
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function team(Club $club, Season $season, string $forcedVenueId): Team
    {
        // Catégorie littérale : ces entités ne portent AUCUNE clé étrangère (chaque lien est
        // une simple colonne guid — c'est précisément pourquoi la cascade existe), donc rien
        // n'exige qu'elle existe. Même idiome qu'`OrphanPinGuardTest`.
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName('U13 F1')
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId(1)->setSessionsPerWeek(1)
            ->setForcedVenueId($forcedVenueId);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** Un groupe de mutualisation et ses membres. @param list<Team> $teams */
    private function sharedGroup(Club $club, Season $season, array $teams): string
    {
        $group = (new SharedTrainingGroup)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCommonSessions(1);
        $this->em->persist($group);
        $this->em->flush();

        foreach ($teams as $team) {
            $this->em->persist((new SharedTrainingGroupTeam)->setClubId($club->getId())->setSeasonId($season->getId())
                ->setGroupId($group->getId())->setTeamId($team->getId()));
        }
        $this->em->flush();

        return $group->getId();
    }

    private function schedule(Club $club, Season $season): Schedule
    {
        // Une version appartient TOUJOURS à un plan (ADR-0002) : on rattache au plan SEASON.
        $plan = $this->em->getRepository(SchedulePlan::class)->findOneBy(['seasonId' => $season->getId(), 'type' => SchedulePlanType::SEASON]);
        self::assertNotNull($plan, 'la saison naît avec son plan SEASON');
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('V1')
            ->setSchedulePlanId($plan->getId())->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }
}
