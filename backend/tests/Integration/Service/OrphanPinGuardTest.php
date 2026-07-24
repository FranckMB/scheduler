<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Service\OrphanPinGuard;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #8 (décision fondateur 2026-07-24) — UN ÉPINGLAGE ORPHELIN BLOQUE LA GÉNÉRATION.
 *
 * Une période possède sa grille ; la refaire (page blanche, gymnase désactivé, recopie)
 * peut laisser un verrou HARD ou une réservation qui ne retombe sur AUCUN créneau. « On ne
 * fait pas de chose magique, on voit et on informe le gestionnaire » : on refuse et on
 * NOMME le gymnase et le jour, plutôt que de replacer la séance en silence.
 *
 * Le dernier cas prouve le BRANCHEMENT : `POST /api/schedules/{id}/generate` rend 422 avec
 * ce message et ne met RIEN en file. Il vit ici, avec le reste du contrat de la garde,
 * plutôt que dans GenerateScheduleComplexityCapTest — ce fichier-là est le NR d'une autre
 * garde (A10, plafonds de complexité) et n'a pas de seed d'overlay.
 */
#[Group('phase1')]
#[Group('integration')]
final class OrphanPinGuardTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private OrphanPinGuard $guard;

    private Club $club;

    private User $user;

    private Season $season;

    private Venue $venue;

    private string $planId;

    /** La version de période : c'est ELLE qu'on cherche à générer. */
    private Schedule $overlay;

    public function testHardLockWithoutAMatchingPeriodSlotIsRefusedAndNamesTheVenueAndDay(): void
    {
        // Jeudi 20:00 : la période n'a que le mardi 18:00 — le verrou ne retombe sur rien.
        $this->em->persist($this->slotTemplate(4, '20:00', LockLevel::HARD));
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message);
        self::assertStringContainsString('jeudi', $message, 'le message nomme le jour');
        self::assertStringContainsString($this->venue->getName(), $message, 'le message nomme le gymnase');
    }

    public function testOrphanReservationIsRefusedToo(): void
    {
        $this->em->persist($this->reservation(5, '19:30'));
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message);
        self::assertStringContainsString('vendredi', $message);
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    public function testPinsThatLandOnAPeriodSlotPassThrough(): void
    {
        // Mardi 18:00 : exactement le créneau que la période possède.
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->persist($this->reservation(2, '18:00'));
        $this->em->flush();

        self::assertNull($this->guard->firstOrphanMessage($this->overlay), 'un épinglage qui retombe sur un créneau de la période ne bloque rien');
    }

    public function testSoftAndNoneOrphansArePlacementsNotPinsSoTheyPassThrough(): void
    {
        // Un placement SOFT/NONE est un RÉSULTAT de solve. Le refuser bloquerait toute
        // régénération après le moindre changement de grille.
        $this->em->persist($this->slotTemplate(4, '20:00', LockLevel::SOFT));
        $this->em->persist($this->slotTemplate(6, '09:00', LockLevel::NONE));
        $this->em->flush();

        self::assertNull($this->guard->firstOrphanMessage($this->overlay));
    }

    public function testSeasonScheduleIsNeverBlocked(): void
    {
        // Le socle définit lui-même sa grille : la notion d'orphelin n'y a pas de sens.
        $seasonVersion = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Socle bis')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($seasonVersion);
        $this->em->flush();

        $orphan = $this->slotTemplate(4, '20:00', LockLevel::HARD)->setScheduleId($seasonVersion->getId());
        $this->em->persist($orphan);
        $this->em->flush();

        self::assertNull($this->guard->firstOrphanMessage($seasonVersion), 'un planning de SAISON n’est jamais bloqué');
    }

    /**
     * Branchement bout-en-bout : la garde est lue par GenerateScheduleController AVANT le
     * passage en PENDING, donc rien n'est mis en file.
     */
    public function testGenerateIsRefusedWith422AndTheGuardMessage(): void
    {
        $this->em->persist($this->slotTemplate(4, '20:00', LockLevel::HARD));
        $this->em->flush();
        $overlayId = $this->overlay->getId();

        $this->client->request('POST', '/api/schedules/' . $overlayId . '/generate', [], [], $this->headers());

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('jeudi', (string) ($body['error'] ?? ''));
        self::assertStringContainsString($this->venue->getName(), (string) ($body['error'] ?? ''));

        // Rien n'a été mis en file : la version est restée DRAFT (le contrôleur rend AVANT
        // setStatus(PENDING) + flush + dispatch, qui sont séquentiels).
        $this->em->clear();
        self::assertSame(ScheduleStatus::DRAFT, $this->em->getRepository(Schedule::class)->find($overlayId)?->getStatus());
    }

    public function testGenerateProceedsOnceEveryPinLandsOnASlot(): void
    {
        // Contre-épreuve du cas précédent : même overlay, épinglage qui retombe bien.
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->flush();
        $overlayId = $this->overlay->getId();

        $this->client->request('POST', '/api/schedules/' . $overlayId . '/generate', [], [], $this->headers());
        self::assertResponseStatusCodeSame(202);

        $this->em->clear();
        self::assertSame(ScheduleStatus::PENDING, $this->em->getRepository(Schedule::class)->find($overlayId)?->getStatus());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->guard = $container->get(OrphanPinGuard::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('OPG ' . $uid)->setSlug('opg-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->user = (new User)->setEmail('opg' . $uid . '@test.com')->setFirstName('O')->setLastName('P');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($this->season);
        $this->em->flush();

        $this->venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Halle Coubertin')->setSource('manual')->setIsActive(true);
        $this->em->persist($this->venue);
        // Grille de SAISON : un seul créneau, mardi 18:00 — la période en héritera.
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($this->venue->getId())->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();

        // Un plan secondaire n'est autorisé qu'au-dessus d'un socle POINTÉ (inv. 13).
        $this->settleSeasonPlan($this->season);

        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2025-10-20'))->setEndDate(new DateTimeImmutable('2025-10-26'));
        $this->em->persist($entry);
        $this->planId = $this->planIdOf($entry);

        $this->overlay = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Overlay Toussaint')->setStatus(ScheduleStatus::DRAFT)->setSchedulePlanId($this->planId);
        $this->em->persist($this->overlay);
        $container->get(SchedulePlanProvisioner::class)->linkSchedule($this->overlay);
        $this->em->flush();
    }

    private function slotTemplate(int $dayOfWeek, string $startTime, LockLevel $lockLevel): ScheduleSlotTemplate
    {
        return (new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setScheduleId($this->overlay->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venue->getId())
            ->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($startTime))->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
    }

    private function reservation(int $dayOfWeek, string $startTime): Reservation
    {
        return (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venue->getId())
            ->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($startTime))->setDurationMinutes(90);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
