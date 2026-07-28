<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — le CÂBLAGE de l'avertissement de capacité (P2-9, revue #317 round 2).
 *
 * `TrainingCapacityCheckerTest` couvre le calcul en isolation, mais rien ne
 * gardait le chemin qui le rend visible : le contrôleur l'appelle-t-il, sur le
 * bon périmètre, et son message ressort-il dans la réponse ? Sans ces tests,
 * retirer l'appel dans `ValidateConstraintsController` laissait toute la suite
 * verte — et la moitié visible par le gestionnaire disparaissait en silence.
 *
 * Le second test garde la décision la plus fragile : le silence sur les PÉRIODES
 * porte sur le PÉRIMÈTRE demandé, pas sur l'identifiant de plan. Une période
 * jamais « Adaptée » n'a pas de plan ; une garde écrite `planId !== null` lui
 * appliquait le calcul de la SAISON ENTIÈRE — en annonçant un manque de créneaux
 * sur une fermeture où personne ne s'entraîne.
 */
#[Group('integration')]
final class ValidateConstraintsCapacityTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    public function testTheSeasonRecapCarriesTheShortfallWarning(): void
    {
        // 3 équipes × 2 séances = 6 demandées ; un seul créneau de capacité 1.
        $this->seedTeams(3);
        $this->seedSlots(1);

        $data = $this->validate(null);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $data['warnings'], 'le message de capacité doit sortir dans la réponse');
        self::assertStringContainsString('demandent 6 créneaux', $data['warnings'][0]);
        self::assertStringContainsString('n’en offrent que 1', $data['warnings'][0]);
        // Un avertissement n'invalide RIEN (règle fondateur du #8).
        self::assertTrue($data['valid']);
    }

    public function testAPeriodWithoutAPlanStaysSilentInsteadOfBorrowingTheSeasonCount(): void
    {
        $this->seedTeams(3);
        $this->seedSlots(1);

        // Période JAMAIS « Adaptée » : aucun `schedule_plan`. C'est le cas qui
        // faisait passer une garde écrite sur `planId !== null`.
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Fermeture de Noël');
        $entry->setStartDate(new DateTimeImmutable('2026-12-20'));
        $entry->setEndDate(new DateTimeImmutable('2027-01-04'));
        $this->em->persist($entry);
        $this->em->flush();

        $data = $this->validate($entry->getId());

        self::assertSame(
            [],
            $data['warnings'],
            'une période ne doit PAS hériter du décompte de la saison — personne ne s’y entraîne',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Capacity Test Club');
        $this->club->setSlug('capacity-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('cap' . $uid . '@test.com');
        $this->user->setFirstName('Cap');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $membership = new ClubUser;
        $membership->setClubId($this->club->getId());
        $membership->setUserId($this->user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2026-2027');
        $this->season->setStartDate(new DateTimeImmutable('2026-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2027-06-30'));
        $this->season->setStatus('active');
        $this->em->persist($this->season);
        $this->em->flush();
    }

    private function seedTeams(int $count): void
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = new Sport;
            $sport->setName('Basketball')->setSlug('basketball-' . uniqid('', true))->setIsActive(true);
            $this->em->persist($sport);
            $this->em->flush();
        }

        $category = new SportCategory;
        $category->setName('U13-' . uniqid('', true))->setClubId($this->club->getId())->setSportId($sport->getId())->setIsCustom(true)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        for ($i = 0; $i < $count; ++$i) {
            $team = new Team;
            $team->setClubId($this->club->getId())
                ->setSeasonId($this->season->getId())
                ->setSportCategoryId($category->getId())
                ->setPriorityTierId(3)
                ->setName('Equipe ' . $i)
                ->setSessionsPerWeek(2)
                ->setIsActive(true);
            $this->em->persist($team);
        }
        $this->em->flush();
    }

    private function seedSlots(int $count): void
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('Gymnase')->setCanSplit(false)->setSource('MANUAL');
        $this->em->persist($venue);
        $this->em->flush();

        for ($i = 0; $i < $count; ++$i) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($this->club->getId())
                ->setSeasonId($this->season->getId())
                ->setVenueId($venue->getId())
                ->setDayOfWeek(1 + $i)
                ->setStartTime(new DateTimeImmutable('18:00'))
                ->setDurationMinutes(90)
                ->setCapacity(1);
            $this->em->persist($slot);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function validate(?string $calendarEntryId): array
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/api/constraints/validate',
            [],
            [],
            ['HTTP_X-Club-Id' => $this->club->getId()],
            null === $calendarEntryId ? '{}' : json_encode(['calendarEntryId' => $calendarEntryId], \JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
