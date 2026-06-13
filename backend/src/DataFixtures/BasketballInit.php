<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BasketballInit implements FixtureInterface, ORMFixtureInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected EntityManagerInterface');
        }

        $manager->getConnection()->executeStatement("SET LOCAL app.club_id = '11111111-1111-1111-1111-111111111111'");

        // --- Sport ---
        $existingSport = $manager->getRepository(Sport::class)->findOneBy(['slug' => 'basket']);
        if ($existingSport instanceof Sport) {
            $sport = $existingSport;
        } else {
            $sport = $this->newEntity(Sport::class);
            $this->hydrate($sport, [
                'name' => 'Basket',
                'slug' => 'basket',
                'icon' => 'basketball',
                'isActive' => true,
            ]);
            $manager->persist($sport);
            $manager->flush();
        }

        // --- Categories ---
        $categories = [
            ['name' => 'U9M', 'gender' => 'M', 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 1],
            ['name' => 'U9F', 'gender' => 'F', 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 2],
            ['name' => 'U11M', 'gender' => 'M', 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 3],
            ['name' => 'U11F', 'gender' => 'F', 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 4],
            ['name' => 'U13M', 'gender' => 'M', 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 5],
            ['name' => 'U13F', 'gender' => 'F', 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 6],
            ['name' => 'U15M', 'gender' => 'M', 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 7],
            ['name' => 'U15F', 'gender' => 'F', 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 8],
            ['name' => 'U18M', 'gender' => 'M', 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 9],
            ['name' => 'U18F', 'gender' => 'F', 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 10],
            ['name' => 'U21M', 'gender' => 'M', 'ageMin' => 19, 'ageMax' => 21, 'sortOrder' => 11],
            ['name' => 'Senior M', 'gender' => 'M', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 12],
            ['name' => 'Senior F', 'gender' => 'F', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 13],
            ['name' => 'Vétéran', 'gender' => null, 'ageMin' => 35, 'ageMax' => 99, 'sortOrder' => 14],
            ['name' => 'Loisir', 'gender' => null, 'ageMin' => null, 'ageMax' => null, 'sortOrder' => 15],
            ['name' => 'Baby basket', 'gender' => null, 'ageMin' => null, 'ageMax' => null, 'sortOrder' => 16],
        ];

        foreach ($categories as $cat) {
            $existing = $manager->getRepository(SportCategory::class)->findOneBy([
                'sportId' => $sport->getId(),
                'name' => $cat['name'],
            ]);
            if (null === $existing) {
                $entity = $this->newEntity(SportCategory::class);
                $this->hydrate($entity, array_merge($cat, [
                    'sport' => $sport,
                    'isCustom' => false,
                    'clubId' => '11111111-1111-1111-1111-111111111111',
                ]));
                $manager->persist($entity);
            }
        }
        $manager->flush();

        // --- Club ---
        $existingClub = $manager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'ARA0069036']);
        if ($existingClub instanceof Club) {
            $club = $existingClub;
        } else {
            $club = $this->newEntity(Club::class);
            $this->hydrate($club, [
                'name' => 'B CHARPENNES CROIX LUIZET',
                'slug' => 'b-charpennes-croix-luizet',
                'ffbbClubCode' => 'ARA0069036',
                'timezone' => 'Europe/Paris',
                'locale' => 'fr',
                'onboardingCompleted' => false,
            ]);
            $manager->persist($club);
            $manager->flush();
        }

        // --- Season ---
        $existingSeason = $manager->getRepository(Season::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => '2025-2026',
        ]);
        if ($existingSeason instanceof Season) {
            $season = $existingSeason;
        } else {
            $season = $this->newEntity(Season::class);
            $this->hydrate($season, [
                'clubId' => $club->getId(),
                'name' => '2025-2026',
                'startDate' => new \DateTimeImmutable('2025-09-01'),
                'endDate' => new \DateTimeImmutable('2026-06-30'),
                'status' => 'active',
            ]);
            $manager->persist($season);
            $manager->flush();
        }

        // --- User ---
        $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => 'mara.mb@bccl.fr']);
        if ($existingUser instanceof User) {
            $user = $existingUser;
        } else {
            $user = $this->newEntity(User::class);
            $this->hydrate($user, [
                'email' => 'mara.mb@bccl.fr',
                'firstName' => 'Mara',
                'lastName' => 'Mb',
                'emailVerifiedAt' => null,
            ]);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'maraboubccl'));
            $manager->persist($user);
            $manager->flush();
        }

        // --- ClubUser ---
        $existingClubUser = $manager->getRepository(ClubUser::class)->findOneBy([
            'clubId' => $club->getId(),
            'userId' => $user->getId(),
        ]);
        if (null === $existingClubUser) {
            $clubUser = $this->newEntity(ClubUser::class);
            $this->hydrate($clubUser, [
                'clubId' => $club->getId(),
                'userId' => $user->getId(),
                'role' => 'admin',
                'isActive' => true,
            ]);
            $manager->persist($clubUser);
            $manager->flush();
        }

        // --- SportCategories for teams ---
        $seniorM = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'Senior M',
        ]);
        assert($seniorM instanceof SportCategory);

        $seniorF = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'Senior F',
        ]);
        assert($seniorF instanceof SportCategory);

        // --- Teams ---
        $teamsData = [
            ['name' => 'SM1', 'sportCategory' => $seniorM, 'level' => 'Regional', 'isCompetition' => true, 'sessionsPerWeek' => 3, 'priorityTierId' => 1],
            ['name' => 'SM2', 'sportCategory' => $seniorM, 'level' => 'Regional', 'isCompetition' => true, 'sessionsPerWeek' => 2, 'priorityTierId' => 2],
            ['name' => 'SF1', 'sportCategory' => $seniorF, 'level' => 'Regional', 'isCompetition' => true, 'sessionsPerWeek' => 3, 'priorityTierId' => 1],
            ['name' => 'SF2', 'sportCategory' => $seniorF, 'level' => 'Regional', 'isCompetition' => true, 'sessionsPerWeek' => 2, 'priorityTierId' => 2],
        ];

        $teams = [];
        foreach ($teamsData as $teamData) {
            $existing = $manager->getRepository(Team::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $teamData['name'],
            ]);
            if ($existing instanceof Team) {
                $teams[$teamData['name']] = $existing;
            } else {
                $team = $this->newEntity(Team::class);
                $this->hydrate($team, [
                    'clubId' => $club->getId(),
                    'seasonId' => $season->getId(),
                    'sportCategoryId' => $teamData['sportCategory']->getId(),
                    'priorityTierId' => $teamData['priorityTierId'],
                    'name' => $teamData['name'],
                    'level' => $teamData['level'],
                    'isCompetition' => $teamData['isCompetition'],
                    'sessionsPerWeek' => $teamData['sessionsPerWeek'],
                    'isActive' => true,
                ]);
                $manager->persist($team);
                $manager->flush();
                $teams[$teamData['name']] = $team;
            }
        }

        // --- Coaches ---
        $coachesData = [
            ['firstName' => 'Maxime', 'lastName' => ''],
            ['firstName' => 'Mara', 'lastName' => ''],
            ['firstName' => 'Emerick', 'lastName' => ''],
            ['firstName' => 'Nico Patin', 'lastName' => ''],
        ];

        $coaches = [];
        foreach ($coachesData as $coachData) {
            $existing = $manager->getRepository(Coach::class)->findOneBy([
                'clubId' => $club->getId(),
                'firstName' => $coachData['firstName'],
            ]);
            if ($existing instanceof Coach) {
                $coaches[$coachData['firstName']] = $existing;
            } else {
                $coach = $this->newEntity(Coach::class);
                $this->hydrate($coach, [
                    'clubId' => $club->getId(),
                    'seasonId' => $season->getId(),
                    'firstName' => $coachData['firstName'],
                    'lastName' => $coachData['lastName'],
                    'isActive' => true,
                ]);
                $manager->persist($coach);
                $manager->flush();
                $coaches[$coachData['firstName']] = $coach;
            }
        }

        // --- TeamCoach links ---
        $teamCoachLinks = [
            ['coach' => 'Maxime', 'team' => 'SM1', 'role' => 'head'],
            ['coach' => 'Mara', 'team' => 'SF2', 'role' => 'head'],
            ['coach' => 'Emerick', 'team' => 'SF1', 'role' => 'head'],
            ['coach' => 'Nico Patin', 'team' => 'SM2', 'role' => 'head'],
        ];

        foreach ($teamCoachLinks as $link) {
            $existing = $manager->getRepository(TeamCoach::class)->findOneBy([
                'teamId' => $teams[$link['team']]->getId(),
                'coachId' => $coaches[$link['coach']]->getId(),
                'role' => $link['role'],
            ]);
            if (null === $existing) {
                $teamCoach = $this->newEntity(TeamCoach::class);
                $this->hydrate($teamCoach, [
                    'clubId' => $club->getId(),
                    'seasonId' => $season->getId(),
                    'teamId' => $teams[$link['team']]->getId(),
                    'coachId' => $coaches[$link['coach']]->getId(),
                    'role' => $link['role'],
                    'isRequired' => true,
                ]);
                $manager->persist($teamCoach);
            }
        }
        $manager->flush();

        // --- CoachPlayerMembership links ---
        $playerLinks = [
            ['coach' => 'Maxime', 'team' => 'SM2'],
            ['coach' => 'Mara', 'team' => 'SM2'],
            ['coach' => 'Emerick', 'team' => 'SM2'],
        ];

        foreach ($playerLinks as $link) {
            $existing = $manager->getRepository(CoachPlayerMembership::class)->findOneBy([
                'coachId' => $coaches[$link['coach']]->getId(),
                'teamId' => $teams[$link['team']]->getId(),
            ]);
            if (null === $existing) {
                $membership = $this->newEntity(CoachPlayerMembership::class);
                $this->hydrate($membership, [
                    'clubId' => $club->getId(),
                    'seasonId' => $season->getId(),
                    'coachId' => $coaches[$link['coach']]->getId(),
                    'teamId' => $teams[$link['team']]->getId(),
                    'isActive' => true,
                ]);
                $manager->persist($membership);
            }
        }
        $manager->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function newEntity(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Entity class %s does not exist yet (Phase 2).', $class));
        }

        return new $class();
    }

    /** @param array<string, mixed> $data */
    private function hydrate(object $entity, array $data): void
    {
        foreach ($data as $key => $value) {
            $setter = 'set'.ucfirst($key);
            if (method_exists($entity, $setter)) {
                $entity->$setter($value);
            }
        }
    }
}
