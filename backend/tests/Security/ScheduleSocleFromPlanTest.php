<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — ADR-0002 lot C4, axe *planning lifecycle* : LE SOCLE SE LIT DU PLAN.
 *
 * « Est-ce le planning principal (le socle) ? » se dérive de `plan.type === SEASON`,
 * jamais de l'absence de `Schedule.calendarEntryId` (doublon d'ancre nullable
 * supprimé en C4). Il n'y a qu'UN plan SEASON par club × saison (inv. 3), donc cette
 * lecture désigne un socle unique et non ambigu.
 *
 * Ruling fondateur (2026-07-17) : une VERSION SANS PLAN n'existe pas — le rattachement
 * est obligatoire. Un schedule non lié (`schedulePlanId` null) est une ANOMALIE : il ne
 * se fait JAMAIS passer pour le socle. Sur les chemins de DÉCISION on LÈVE (fail-loud) —
 * l'alternative silencieuse (le traiter en saison) générerait la saison avec les
 * contraintes d'une période, sans erreur ni signal : c'est le piège d'ancre nullable
 * de C2/C3, pour la troisième fois.
 *
 * Vu P4-21, ces tests ont été vérifiés en CASSANT le code d'abord (fallback socle sur
 * l'absence de plan) : ils rougissent, puis repassent au vert une fois la garde en place.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleSocleFromPlanTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Le point de passage que TOUT site de décision consomme (génération, validation,
     * régénération) : la vérité « socle ? » vient du type du plan, et le troisième état
     * (aucun plan) LÈVE au lieu de retomber sur « saison ».
     */
    public function testTheSocleTruthComesFromThePlanTypeAndTheThirdStateRaises(): void
    {
        [$user, $club, $season] = $this->seed();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);

        // Une version de saison LIÉE : c'est le socle, et elle ne pointe aucune période.
        $seasonVersion = $this->linkedSeasonVersion($season);
        self::assertTrue($provisioner->isSeasonSchedule($seasonVersion), 'une version du plan SEASON EST le socle');
        self::assertNull($provisioner->periodEntryIdOf($seasonVersion), 'le socle ne pointe aucune période');

        // Un overlay LIÉ (plan CLOSURE/HOLIDAY) : jamais le socle, et il pointe sa période.
        [$overlay, $entryId] = $this->linkedOverlay($user, $club, $season);
        self::assertFalse($provisioner->isSeasonSchedule($overlay), 'un overlay de période n’est jamais le socle');
        self::assertSame($entryId, $provisioner->periodEntryIdOf($overlay), 'l’overlay pointe sa période via son plan');

        // Une version SANS plan (anomalie) : le 3e état LÈVE — il ne retombe pas sur « socle ».
        $orphan = $this->unlinkedVersion($season, ScheduleStatus::COMPLETED);
        $this->expectException(LogicException::class);
        $provisioner->isSeasonSchedule($orphan);
    }

    /**
     * Chemin de PROD : une version non liée poussée dans /regenerate ne se fait pas passer
     * pour le socle. Sous l'ancienne logique (`null === calendarEntryId` ⇒ saison), ce POST
     * régénérait la saison ; sous C4 la garde « socle ? » lève, la requête échoue fort et
     * AUCUNE version n'est mintée comme si c'était le calendrier de la saison.
     */
    public function testRegenerateNeverTreatsAnUnlinkedVersionAsTheSocle(): void
    {
        [$user, $club, $season] = $this->seed();
        // On pointe un socle pour écarter le SocleGuard : ce n'est pas LUI la cause du refus.
        $this->settleSeasonPlan($season);

        $orphan = $this->unlinkedVersion($season, ScheduleStatus::COMPLETED);
        $before = $this->scheduleCount($club->getId());

        $this->client->request('POST', '/api/schedules/' . $orphan->getId() . '/regenerate', [], [], $this->authHeaders($user));

        self::assertGreaterThanOrEqual(
            500,
            $this->client->getResponse()->getStatusCode(),
            'un état impossible (version sans plan) échoue fort — il ne passe pas pour le socle',
        );
        self::assertSame(
            $before,
            $this->scheduleCount($club->getId()),
            'aucune version n’a été créée : l’anomalie n’a pas été régénérée comme la saison',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function linkedSeasonVersion(Season $season): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('V socle')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    /**
     * @return array{0: Schedule, 1: string} la version overlay liée + l'id de sa période
     */
    private function linkedOverlay(User $user, Club $club, Season $season): array
    {
        $entryId = $this->postClosurePeriod($user);

        $overlay = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('V overlay')
            ->setStatus(ScheduleStatus::COMPLETED)
            ->setCalendarEntryId($entryId);
        $this->em->persist($overlay);
        $this->em->flush();
        $this->linkSeededSchedule($overlay);

        return [$overlay, $entryId];
    }

    private function unlinkedVersion(Season $season, ScheduleStatus $status): Schedule
    {
        // Volontairement NON liée (schedulePlanId null) et sans calendarEntryId : l'état
        // exact qui, sous l'ancienne logique, se faisait passer pour le socle.
        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('V orpheline')
            ->setStatus($status);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function postClosurePeriod(User $user): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Gymnase fermé',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
            'periodType' => 'closure',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function scheduleCount(string $clubId): int
    {
        $this->scopeGucToClub($clubId);

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule WHERE club_id = :cid',
            ['cid' => $clubId],
        );
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club socle-from-plan');
        $club->setSlug('csfp-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CSFP' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('csfp-' . $uid . '@test.com');
        $user->setFirstName('So');
        $user->setLastName('Cle');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
