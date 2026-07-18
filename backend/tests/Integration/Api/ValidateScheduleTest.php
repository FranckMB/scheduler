<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * §7.1 planning lifecycle — ADR-0002 inv. 1: validating a COMPLETED version makes
 * the plan POINT at it. There is no VALIDATED status: "validated" is derived from
 * the pointer alone. Choosing also DELETES the sibling versions of the same scope
 * (never the overlays); a sibling still generating blocks the choice (409). Only
 * within the caller's own club, and only when completed.
 */
#[Group('phase1')]
#[Group('integration')]
final class ValidateScheduleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testValidateMakesThePlanPointAtTheVersion(): void
    {
        [$user, , $season] = $this->seed('VAL1');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame($schedule->getId(), $this->chosenPlanVersion($season), 'validating = the plan points at this version');
        // The version keeps the solver's verdict: "chosen" is carried by the
        // pointer, never mirrored back onto the status.
        $reloaded = $this->em->getRepository(Schedule::class)->find($schedule->getId());
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded?->getStatus());
    }

    public function testTheChosenVersionSurfacesOnMe(): void
    {
        [$user, , $season] = $this->seed('VAL5');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");
        self::assertResponseIsSuccessful();

        // The frontend reads the whole "is the season settled?" question from
        // /api/me.seasonPlan — the single seam (stateless firewall → Bearer).
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->client->request('GET', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt->create($user),
        ]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($schedule->getId(), $me['seasonPlan']['chosenScheduleId']);
        self::assertTrue($me['seasonPlan']['hasFinishedVersion']);
    }

    public function testValidateDeletesSiblingSeasonVersionsButNotOverlays(): void
    {
        [$user, , $season] = $this->seed('VAL7');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $failed = $this->createSchedule($season, ScheduleStatus::FAILED);
        // Une VRAIE période (et son plan né du geste) : un overlay se rattache à un plan réel.
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        // inv. 1: the plan keeps the ONE version it points at — the losers are
        // deleted, not archived. There is no hidden safety net any more.
        self::assertSame($v2->getId(), $this->chosenPlanVersion($season));
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'sibling COMPLETED version deleted');
        self::assertNull($this->em->getRepository(Schedule::class)->find($failed->getId()), 'sibling FAILED version deleted');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'an overlay is NEVER touched by a season-plan choice');
    }

    public function testValidateBlockedWhileSiblingIsGenerating(): void
    {
        [$user, , $season] = $this->seed('VAL8');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->createSchedule($season, ScheduleStatus::GENERATING);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate");

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v1->getId())?->getStatus(), 'nothing committed when a sibling is mid-solve');
    }

    public function testValidatingAnotherVersionWithOverlaysRequiresConfirmation(): void
    {
        // The plan points at V1, with a period overlay built on it; choosing V2
        // MOVES the pointer → the overlay would silently compose over a different
        // base plan (inv. 14). Same destructive idiom as reopen: 409 overlays_exist,
        // then confirmDeleteOverlays deletes the overlay.
        [$user, , $season] = $this->seed('VAL9');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1);
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $this->choosePlanVersion($overlay); // lot D-b : un plan secondaire RÉEL = plan validé
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        // Two sequential authenticated calls → Bearer on each (stateless firewall).
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json'];
        // Without the confirm flag → 409 escalation, nothing mutated.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $body['code'] ?? null);
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v2->getId())?->getStatus(), 'nothing committed on the 409 path');

        // With the confirm flag → overlay deleted, baseline moved, V2 validated.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth, json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'the stale overlay is deleted after explicit confirmation');
        self::assertSame($v2->getId(), $this->chosenPlanVersion($season), 'the pointer moved to V2');
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'the version it no longer points at is deleted');
    }

    public function testValidatingWithLiveOverlaysAsksEvenWhenThePlanPointsAtNothing(): void
    {
        // Le plan est un espace de travail (pointeur null) MAIS des plans secondaires
        // survivent — cas réel : le socle a été rouvert, ou la donnée vient d'avant la
        // bascule. Choisir une version donne alors aux overlays un autre socle que celui
        // sur lequel ils ont été bâtis. La garde doit s'armer sur « le plan ne pointe pas
        // DÉJÀ cette version », pas sur « le plan pointe quelque chose » — sinon elle
        // saute exactement là où elle est nécessaire, et sans rien dire.
        [$user, , $season] = $this->seed('VAL10');
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $this->choosePlanVersion($overlay); // un plan secondaire VALIDÉ survit, même sans socle pointé (donnée d'avant la bascule / socle rouvert)
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        self::assertNull($this->chosenPlanVersion($season), 'le plan de la SAISON ne pointe rien : c\'est le cas qui désarmait la garde');

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate", [], [], $auth);

        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $body['code'] ?? null);
        $this->em->clear();
        self::assertNull($this->chosenPlanVersion($season), 'rien n\'est commité sur le 409');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'le plan secondaire survit tant que rien n\'est confirmé');
    }

    public function testFirstValidationWithoutOverlaysNeedsNoConfirmation(): void
    {
        // Le pendant du test ci-dessus : sans plan secondaire, la garde ne coûte rien.
        // Sinon on aurait remplacé un trou par une demande de confirmation absurde à
        // la toute première validation d'un club.
        [$user, , $season] = $this->seed('VAL11');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate");

        self::assertResponseIsSuccessful();
        self::assertSame($v1->getId(), $this->chosenPlanVersion($season));
    }

    public function testValidatingAVersionThatVanishedMeanwhileIsRefused(): void
    {
        // LA course qui m'a échappé deux fois. Deux onglets valident V1 et V2 : la
        // première supprime V2 (sa sœur), la seconde arrive avec une entité V2 chargée
        // AVANT le verrou. Si elle ne relit pas la BASE, elle croit V2 COMPLETED,
        // pointe le plan sur une ligne morte (colonne guid nue, aucune FK) et supprime
        // V1 : zéro version, pointeur fantôme, club renvoyé au wizard.
        //
        // On simule l'issue de la course : la version disparaît de la base pendant que
        // la requête la tient encore en mémoire.
        [$user, , $season] = $this->seed('VAL12');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $v2Id = $v2->getId();

        // Suppression HORS ORM : l'identity map garde V2, comme la requête concurrente.
        $this->em->getConnection()->executeStatement('DELETE FROM schedule WHERE id = :id', ['id' => $v2Id]);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v2Id}/validate");

        self::assertResponseStatusCodeSame(409, 'une version disparue ne peut pas être choisie');
        $this->em->clear();
        self::assertNull($this->chosenPlanVersion($season), 'le plan ne pointe pas une ligne morte');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'et la survivante n\'est pas emportée');
    }

    public function testNonCompletedScheduleCannotBeValidated(): void
    {
        [$user, , $season] = $this->seed('VAL2');
        $schedule = $this->createSchedule($season, ScheduleStatus::DRAFT);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignScheduleIsNotAccessible(): void
    {
        [$user] = $this->seed('VAL3');
        [, , $otherSeason] = $this->seed('VAL4');
        $foreign = $this->createSchedule($otherSeason, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$foreign->getId()}/validate");

        // The controller guard rejects a schedule from another club (the caller's
        // club is resolved from the JWT); RLS is a second line in production.
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('B');
        $user->setLastName('L3');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);

        $this->em->flush();

        return [$user, $club, $season];
    }

    private function createSchedule(Season $season, ScheduleStatus $status, ?string $calendarEntryId = null): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        // Prod links every version at creation ; sans ça, depuis C4 la validation
        // lèverait sur une version sans plan (periodEntryIdOf). linkSeededSchedule
        // persiste et numérote lui-même — la Schedule ne doit PAS être flushée avant.
        $this->linkSeededSchedule($schedule, $calendarEntryId);

        return $schedule;
    }
}
