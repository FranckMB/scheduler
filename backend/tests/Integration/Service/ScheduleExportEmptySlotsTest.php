<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Export\ScheduleExportDataProvider;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les « créneaux vides » d'un export (PDF/XLSX) viennent de la COUCHE de la version
 * exportée, et d'elle seule.
 *
 * #8 — depuis qu'une période possède sa grille (copie du modèle de saison à la naissance
 * du plan), le même créneau existe autant de fois en base qu'il y a de plans. L'export
 * lisait tous les créneaux du club/saison sans distinction : un club à trois périodes
 * voyait chaque créneau vide répété quatre fois sur son planning de saison.
 */
#[Group('integration')]
final class ScheduleExportEmptySlotsTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleExportDataProvider $provider;

    public function testEachLayerExportsItsOwnEmptySlotsOnly(): void
    {
        [$club, $season] = $this->seed();
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Barros');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

        // Un créneau de saison, vide (aucune séance placée dessus).
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek(3);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
        $this->em->flush();

        $seasonVersion = new Schedule;
        $seasonVersion->setClubId($club->getId());
        $seasonVersion->setSeasonId($season->getId());
        $seasonVersion->setName('Socle');
        $seasonVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($seasonVersion);
        $this->em->flush();

        // Le geste « Adapter » : la période naît avec SA copie du même créneau.
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Toussaint');
        $entry->setStartDate(new DateTimeImmutable('+1 month'));
        $entry->setEndDate(new DateTimeImmutable('+1 month +7 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $periodVersion = new Schedule;
        $periodVersion->setClubId($club->getId());
        $periodVersion->setSeasonId($season->getId());
        $periodVersion->setName('Toussaint V1');
        $periodVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($periodVersion, $entry->getId());
        $this->em->flush();
        self::assertCount(
            2,
            $this->em->getRepository(VenueTrainingSlot::class)->findBy(['venueId' => $venue->getId()]),
            'le décor du bug : le même créneau existe deux fois en base, un par couche',
        );

        // Chaque planning n'affiche QUE le sien — une fois, pas deux.
        self::assertCount(1, $this->provider->load($seasonVersion)->emptySlots, 'le planning de saison montre son créneau vide une seule fois');
        self::assertCount(1, $this->provider->load($periodVersion)->emptySlots, 'la période montre le sien, pas celui du socle en plus');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->provider = self::getContainer()->get(ScheduleExportDataProvider::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('EXP Club');
        $club->setSlug('exp-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('EXP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('exp-' . $uid . '@test.com');
        $user->setFirstName('E');
        $user->setLastName('X');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
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

        return [$club, $season];
    }
}
