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
     * régénération) : la vérité « socle ? » vient du TYPE du plan (SEASON), pas d'un doublon.
     * Une version a TOUJOURS un plan (lot D) ; si sa LIGNE de plan a disparu sous les pieds
     * (reset concurrent), la lecture LÈVE plutôt que de deviner un socle.
     */
    public function testTheSocleTruthComesFromThePlanType(): void
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

        // Plan disparu (reset concurrent) : la ligne du plan n'existe plus → LÈVE.
        $this->scopeGucToClub($club->getId());
        $this->em->getConnection()->executeStatement('DELETE FROM schedule_plan WHERE id = :pid', ['pid' => $seasonVersion->getSchedulePlanId()]);
        $this->expectException(LogicException::class);
        $provisioner->isSeasonSchedule($seasonVersion);
    }

    /**
     * NR lot D — L'INVARIANT EST SCELLÉ EN BASE : « une version sans plan n'existe pas » n'est
     * plus une garde applicative contournable, c'est une impossibilité STRUCTURELLE. Le type PHP
     * est non-nullable (on ne peut pas construire une version orpheline) ET les colonnes
     * `schedule_plan_id` / `version_number` sont NOT NULL (la DB refuse tout INSERT sans plan).
     */
    public function testTheNoVersionWithoutPlanInvariantIsSealedInTheSchema(): void
    {
        [, $club] = $this->seed();
        $this->scopeGucToClub($club->getId());

        foreach (['schedule_plan_id', 'version_number'] as $column) {
            $nullable = $this->em->getConnection()->fetchOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_name = \'schedule\' AND column_name = :col',
                ['col' => $column],
            );
            self::assertSame('NO', $nullable, \sprintf('schedule.%s est NOT NULL — une version sans plan est impossible en base (lot D)', $column));
        }
    }

    /**
     * NR PR2 — LE CONTRAT DE CRÉATION : une version se crée SOUS un plan nommé
     * (`schedulePlanId`), plus « pour une période ». Le plan la LIE et la TYPE (`planType`) ;
     * un plan étranger/inconnu est refusé (422, validation tenant) ; sans plan ⇒ le plan
     * SEASON (le socle). Et le champ redondant `calendarEntryId` a disparu de la sortie API.
     */
    public function testAVersionIsCreatedUnderANamedPlanWhichLinksAndTypesIt(): void
    {
        [$user, $club, $season] = $this->seed();
        // inv. 13 : un overlay se bâtit sur un socle pointé.
        $this->settleSeasonPlan($season);
        $entryId = $this->postClosurePeriod($user);
        $periodPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entryId);
        self::assertIsString($periodPlanId, 'la période née du geste porte un plan');

        // Sous le plan de la période → overlay lié au bon plan, sans calendarEntryId exposé.
        $overlay = $this->postSchedule($user, ['name' => 'V période', 'status' => 'DRAFT', 'schedulePlanId' => $periodPlanId]);
        self::assertSame($periodPlanId, $overlay['schedulePlanId'], 'la version se rattache au plan nommé');
        self::assertArrayNotHasKey('calendarEntryId', $overlay, 'le doublon d’ancre a disparu de la sortie API (C4)');
        // planType est dérivé + batché à la LECTURE (pas dans la réponse POST) — on le relit.
        self::assertSame('CLOSURE', $this->getSchedule($user, (string) $overlay['id'])['planType'], 'son type vient du plan');

        // Sans schedulePlanId → le socle (plan SEASON).
        $seasonVersion = $this->postSchedule($user, ['name' => 'V saison', 'status' => 'DRAFT']);
        self::assertSame('SEASON', $this->getSchedule($user, (string) $seasonVersion['id'])['planType'], 'sans plan nommé, la version naît sous le socle');

        // Un plan inconnu/étranger est refusé (le back valide l’appartenance au club).
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'x', 'status' => 'DRAFT', 'schedulePlanId' => '99999999-9999-4999-8999-999999999999',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un plan inconnu/étranger au club est refusé');
    }

    /**
     * NR PR2 (isolation saison) — nommer un plan d'une AUTRE saison est refusé (422). Sans ce
     * garde-fou, la sélection de saison par le corps du POST contournerait la garde archive :
     * la requête passe `assertWritable` sur la saison active, puis s'estampillait de la saison
     * (potentiellement archivée) du plan — une écriture dans une saison gelée. Avant C4, le
     * find() season-filtré de l'entrée refusait déjà ce cas ; C4 (SQL brut) doit le refaire.
     */
    public function testCreatingAVersionUnderAPlanOfAnotherSeasonIsRefused(): void
    {
        [$user, $club, $season] = $this->seed();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);

        // Une AUTRE saison du même club (N-1) et son plan SEASON.
        $this->scopeGucToClub($club->getId());
        $otherYear = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1;
        $otherSeason = new Season;
        $otherSeason->setClubId($club->getId());
        $otherSeason->setName((string) $otherYear);
        $otherSeason->setStartDate(new DateTimeImmutable($otherYear . '-08-01'));
        $otherSeason->setEndDate(new DateTimeImmutable(($otherYear + 1) . '-07-15'));
        $otherSeason->setStatus('archived');
        $otherSeason->setTransitionData([]);
        $this->em->persist($otherSeason);
        $this->em->flush();
        $otherPlanId = $provisioner->ensureSeasonPlanId($otherSeason->getId());
        $this->em->flush();
        self::assertIsString($otherPlanId);

        // La saison active (résolue) est celle du seed (année courante) ; le plan est de N-1.
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'x', 'status' => 'DRAFT', 'schedulePlanId' => $otherPlanId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un plan d’une autre saison est refusé — pas de contournement de la garde archive');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postSchedule(User $user, array $body): array
    {
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed> la version relue (planType dérivé + batché par le provider)
     */
    private function getSchedule(User $user, string $id): array
    {
        $this->client->request('GET', '/api/schedules/' . $id, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function linkedSeasonVersion(Season $season): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('V socle')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule); // lot D : pose le plan AVANT de persister

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
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($overlay, $entryId); // lot D : pose le plan AVANT de persister

        return [$overlay, $entryId];
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
