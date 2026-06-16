<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueAvailability;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\LockLevel;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLevel;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BasketballInit implements FixtureInterface, ORMFixtureInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('Expected EntityManagerInterface');
        }

        $manager->getConnection()->executeStatement('SET LOCAL app.club_id = \'11111111-1111-1111-1111-111111111111\'');

        // --- Sport ---
        $existingSport = $manager->getRepository(Sport::class)->findOneBy(['slug' => 'basket']);
        if ($existingSport instanceof Sport) {
            $sport = $existingSport;
        } else {
            $sport = new Sport;
            $sport->setName('Basket');
            $sport->setSlug('basket');
            $sport->setIcon('basketball');
            $sport->setIsActive(true);
            $manager->persist($sport);
        }

        // --- Categories ---
        $categories = [
            ['name' => 'U9M', 'gender' => Gender::M, 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 1],
            ['name' => 'U9F', 'gender' => Gender::F, 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 2],
            ['name' => 'U11M', 'gender' => Gender::M, 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 3],
            ['name' => 'U11F', 'gender' => Gender::F, 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 4],
            ['name' => 'U13M', 'gender' => Gender::M, 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 5],
            ['name' => 'U13F', 'gender' => Gender::F, 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 6],
            ['name' => 'U15M', 'gender' => Gender::M, 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 7],
            ['name' => 'U15F', 'gender' => Gender::F, 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 8],
            ['name' => 'U18M', 'gender' => Gender::M, 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 9],
            ['name' => 'U18F', 'gender' => Gender::F, 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 10],
            ['name' => 'U21M', 'gender' => Gender::M, 'ageMin' => 19, 'ageMax' => 21, 'sortOrder' => 11],
            ['name' => 'Senior M', 'gender' => Gender::M, 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 12],
            ['name' => 'Senior F', 'gender' => Gender::F, 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 13],
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
                $entity = new SportCategory;
                $entity->setName($cat['name']);
                $entity->setGender($cat['gender']);
                $entity->setAgeMin($cat['ageMin']);
                $entity->setAgeMax($cat['ageMax']);
                $entity->setSortOrder($cat['sortOrder']);
                $entity->setSport($sport);
                $entity->setIsCustom(false);
                $entity->setClubId('11111111-1111-1111-1111-111111111111');
                $manager->persist($entity);
            }
        }
        $manager->flush();

        // --- Club ---
        $existingClub = $manager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'ARA0069036']);
        if ($existingClub instanceof Club) {
            $club = $existingClub;
        } else {
            $club = new Club;
            $club->setName('B CHARPENNES CROIX LUIZET');
            $club->setSlug('b-charpennes-croix-luizet');
            $club->setFfbbClubCode('ARA0069036');
            $club->setTimezone('Europe/Paris');
            $club->setLocale('fr');
            $club->setOnboardingCompleted(false);
            $manager->persist($club);
        }
        $manager->flush();

        // ============================================================
        // SECTION 1 — PRIORITY TIERS
        // ============================================================
        $tiersData = [
            ['id' => 1, 'label' => 'S', 'name' => 'Elite', 'color' => '#FFD700', 'orToolsWeight' => 10000, 'defaultMinSessions' => 3],
            ['id' => 2, 'label' => 'A', 'name' => 'Régional+', 'color' => '#C0C0C0', 'orToolsWeight' => 1000, 'defaultMinSessions' => 2],
            ['id' => 3, 'label' => 'B', 'name' => 'Régional', 'color' => '#CD7F32', 'orToolsWeight' => 100, 'defaultMinSessions' => 2],
            ['id' => 4, 'label' => 'C', 'name' => 'Départemental', 'color' => '#3498DB', 'orToolsWeight' => 10, 'defaultMinSessions' => 2],
            ['id' => 5, 'label' => 'D', 'name' => 'Loisir', 'color' => '#95A5A6', 'orToolsWeight' => 1, 'defaultMinSessions' => 1],
        ];

        foreach ($tiersData as $tierData) {
            $existing = $manager->getRepository(PriorityTier::class)->find($tierData['id']);
            if (!$existing instanceof PriorityTier) {
                $tier = new PriorityTier;
                $tier->setId($tierData['id']);
                $tier->setLabel($tierData['label']);
                $tier->setName($tierData['name']);
                $tier->setColor($tierData['color']);
                $tier->setOrToolsWeight($tierData['orToolsWeight']);
                $tier->setDefaultMinSessions($tierData['defaultMinSessions']);
                $manager->persist($tier);
            }
        }
        $manager->flush();

        // --- Season ---
        $existingSeason = $manager->getRepository(Season::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => '2025-2026',
        ]);
        if ($existingSeason instanceof Season) {
            $season = $existingSeason;
        } else {
            $season = new Season;
            $season->setClubId($club->getId());
            $season->setName('2025-2026');
            $season->setStartDate(new DateTimeImmutable('2025-09-01'));
            $season->setEndDate(new DateTimeImmutable('2026-06-30'));
            $season->setStatus('active');
            $manager->persist($season);
        }

        // --- User ---
        $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => 'mara.mb@bccl.fr']);
        if ($existingUser instanceof User) {
            $user = $existingUser;
        } else {
            $user = new User;
            $user->setEmail('mara.mb@bccl.fr');
            $user->setFirstName('Mara');
            $user->setLastName('Mb');
            $user->setEmailVerifiedAt(null);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'maraboubccl'));
            $manager->persist($user);
        }

        // --- ClubUser ---
        $existingClubUser = $manager->getRepository(ClubUser::class)->findOneBy([
            'clubId' => $club->getId(),
            'userId' => $user->getId(),
        ]);
        if (null === $existingClubUser) {
            $clubUser = new ClubUser;
            $clubUser->setClubId($club->getId());
            $clubUser->setUserId($user->getId());
            $clubUser->setRole('admin');
            $clubUser->setIsActive(true);
            $manager->persist($clubUser);
        }
        $manager->flush();

        // ============================================================
        // SECTION 2 — VENUES
        // ============================================================
        $venuesData = [
            ['name' => 'Gymnase Armand', 'var' => 'vArmand'],
            ['name' => 'ADN', 'var' => 'vAdn'],
            ['name' => 'Debarros', 'var' => 'vDebarros'],
            ['name' => 'De Barros Annexe', 'var' => 'vDebarrosAnnexe'],
            ['name' => 'Jean Vilar', 'var' => 'vJeanVilar'],
            ['name' => 'Tonkin', 'var' => 'vTonkin'],
            ['name' => 'Colette Besson', 'var' => 'vColette'],
            ['name' => 'Matéo', 'var' => 'vMateo'],
            ['name' => 'Camus', 'var' => 'vCamus'],
        ];

        $venues = [];
        foreach ($venuesData as $vd) {
            $existing = $manager->getRepository(Venue::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $vd['name'],
            ]);
            if ($existing instanceof Venue) {
                $venues[$vd['var']] = $existing;
            } else {
                $venue = new Venue;
                $venue->setClubId($club->getId());
                $venue->setSeasonId($season->getId());
                $venue->setName($vd['name']);
                $venue->setSource('fixture');
                $venue->setIsActive(true);
                $manager->persist($venue);
                $venues[$vd['var']] = $venue;
            }
        }
        $manager->flush();

        // ============================================================
        // SECTION — VENUE AVAILABILITIES (default hours)
        // ============================================================
        $defaultAvailabilities = [
            ['day' => 1, 'start' => '16:00', 'end' => '22:30'],
            ['day' => 2, 'start' => '16:00', 'end' => '22:30'],
            ['day' => 3, 'start' => '08:00', 'end' => '22:30'],
            ['day' => 4, 'start' => '16:00', 'end' => '22:30'],
            ['day' => 5, 'start' => '16:00', 'end' => '22:30'],
            ['day' => 6, 'start' => '08:00', 'end' => '14:00'],
        ];

        foreach ($venues as $venue) {
            foreach ($defaultAvailabilities as $avail) {
                $existing = $manager->getRepository(VenueAvailability::class)->findOneBy([
                    'venueId' => $venue->getId(),
                    'dayOfWeek' => $avail['day'],
                ]);
                if (null === $existing) {
                    $va = new VenueAvailability;
                    $va->setClubId($club->getId());
                    $va->setSeasonId($season->getId());
                    $va->setVenueId($venue->getId());
                    $va->setDayOfWeek($avail['day']);
                    $va->setStartTime(new DateTimeImmutable($avail['start']));
                    $va->setEndTime(new DateTimeImmutable($avail['end']));
                    $manager->persist($va);
                }
            }
        }
        $manager->flush();

        // --- SportCategories for teams ---
        $seniorM = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'Senior M',
        ]);
        \assert($seniorM instanceof SportCategory);

        $seniorF = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'Senior F',
        ]);
        \assert($seniorF instanceof SportCategory);

        // ============================================================
        // SECTION 3 — SPORT CATEGORIES (fetch additional ones)
        // ============================================================
        $u13F = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'U13F',
        ]);
        \assert($u13F instanceof SportCategory);

        $u15M = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'U15M',
        ]);
        \assert($u15M instanceof SportCategory);

        $u15F = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'U15F',
        ]);
        \assert($u15F instanceof SportCategory);

        $u18M = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'U18M',
        ]);
        \assert($u18M instanceof SportCategory);

        $u18F = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'U18F',
        ]);
        \assert($u18F instanceof SportCategory);

        $u21M = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'U21M',
        ]);
        \assert($u21M instanceof SportCategory);

        $veteran = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'Vétéran',
        ]);
        \assert($veteran instanceof SportCategory);

        $loisir = $manager->getRepository(SportCategory::class)->findOneBy([
            'sportId' => $sport->getId(),
            'name' => 'Loisir',
        ]);
        \assert($loisir instanceof SportCategory);

        // --- Existing Teams ---
        $teamsData = [
            ['name' => 'SM1', 'sportCategory' => $seniorM, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 1, 'gender' => Gender::M],
            ['name' => 'SM2', 'sportCategory' => $seniorM, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'SF1', 'sportCategory' => $seniorF, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 1, 'gender' => Gender::F],
            ['name' => 'SF2', 'sportCategory' => $seniorF, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::F],
        ];

        /** @var array<string, Team> $teams */
        $teams = [];
        foreach ($teamsData as $teamData) {
            $existing = $manager->getRepository(Team::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $teamData['name'],
            ]);
            if ($existing instanceof Team) {
                $teams[$teamData['name']] = $existing;
            } else {
                $team = new Team;
                $team->setClubId($club->getId());
                $team->setSeasonId($season->getId());
                $team->setSportCategoryId($teamData['sportCategory']->getId());
                $team->setPriorityTierId($teamData['priorityTierId']);
                $team->setName($teamData['name']);
                $team->setLevel($teamData['level']);
                $team->setGender($teamData['gender']);
                $team->setSessionsPerWeek($teamData['sessionsPerWeek']);
                $team->setIsActive(true);
                $manager->persist($team);
                $teams[$teamData['name']] = $team;
            }
        }

        // ============================================================
        // SECTION 4 — NEW TEAMS
        // ============================================================
        $newTeamsData = [
            ['name' => 'SM3', 'sportCategory' => $seniorM, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'SM4', 'sportCategory' => $seniorM, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::M],
            ['name' => 'Veterans', 'sportCategory' => $veteran, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'U21M1', 'sportCategory' => $u21M, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U21M2', 'sportCategory' => $u21M, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'SF3', 'sportCategory' => $seniorF, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U18M1', 'sportCategory' => $u18M, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U18M2', 'sportCategory' => $u18M, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U18F1', 'sportCategory' => $u18F, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U18F2', 'sportCategory' => $u18F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U18F3', 'sportCategory' => $u18F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => 'U15M1', 'sportCategory' => $u15M, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U15M2', 'sportCategory' => $u15M, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U15F1', 'sportCategory' => $u15F, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U15F2', 'sportCategory' => $u15F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U15F3', 'sportCategory' => $u15F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => 'U13F1', 'sportCategory' => $u13F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
        ];

        foreach ($newTeamsData as $teamData) {
            $existing = $manager->getRepository(Team::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $teamData['name'],
            ]);
            if ($existing instanceof Team) {
                $teams[$teamData['name']] = $existing;
            } else {
                $team = new Team;
                $team->setClubId($club->getId());
                $team->setSeasonId($season->getId());
                $team->setSportCategoryId($teamData['sportCategory']->getId());
                $team->setPriorityTierId($teamData['priorityTierId']);
                $team->setName($teamData['name']);
                $team->setLevel($teamData['level']);
                $team->setGender($teamData['gender']);
                $team->setSessionsPerWeek($teamData['sessionsPerWeek']);
                $team->setIsActive(true);
                $manager->persist($team);
                $teams[$teamData['name']] = $team;
            }
        }
        $manager->flush();

        // Extract typed team references for PHPStan level 8
        $sm1 = $teams['SM1'];
        $sm2 = $teams['SM2'];
        $sf1 = $teams['SF1'];
        $sf2 = $teams['SF2'];
        $sm3 = $teams['SM3'];
        $sm4 = $teams['SM4'];
        $veterans = $teams['Veterans'];
        $u21m1 = $teams['U21M1'];
        $u21m2 = $teams['U21M2'];
        $sf3 = $teams['SF3'];
        $u18m1 = $teams['U18M1'];
        $u18m2 = $teams['U18M2'];
        $u18f1 = $teams['U18F1'];
        $u18f2 = $teams['U18F2'];
        $u18f3 = $teams['U18F3'];
        $u15m1 = $teams['U15M1'];
        $u15m2 = $teams['U15M2'];
        $u15f1 = $teams['U15F1'];
        $u15f2 = $teams['U15F2'];
        $u15f3 = $teams['U15F3'];
        $u13f1 = $teams['U13F1'];

        // --- Existing Coaches ---
        $coachesData = [
            ['firstName' => 'Maxime', 'lastName' => ''],
            ['firstName' => 'Mara', 'lastName' => ''],
            ['firstName' => 'Emerick', 'lastName' => ''],
            ['firstName' => 'Nico Patin', 'lastName' => ''],
        ];

        /** @var array<string, Coach> $coaches */
        $coaches = [];
        foreach ($coachesData as $coachData) {
            $existing = $manager->getRepository(Coach::class)->findOneBy([
                'clubId' => $club->getId(),
                'firstName' => $coachData['firstName'],
            ]);
            if ($existing instanceof Coach) {
                $coaches[$coachData['firstName']] = $existing;
            } else {
                $coach = new Coach;
                $coach->setClubId($club->getId());
                $coach->setSeasonId($season->getId());
                $coach->setFirstName($coachData['firstName']);
                $coach->setLastName($coachData['lastName']);
                $coach->setIsActive(true);
                $manager->persist($coach);
                $coaches[$coachData['firstName']] = $coach;
            }
        }

        // ============================================================
        // SECTION 5 — NEW COACHES
        // ============================================================
        $newCoachesData = [
            ['firstName' => 'Enzo', 'lastName' => ''],
            ['firstName' => 'Thomas', 'lastName' => ''],
            ['firstName' => 'Flo', 'lastName' => ''],
            ['firstName' => 'Chris', 'lastName' => ''],
            ['firstName' => 'Marlon', 'lastName' => ''],
            ['firstName' => 'Lionel', 'lastName' => ''],
            ['firstName' => 'Nicolas', 'lastName' => 'Barilleau'],
            ['firstName' => 'Ines', 'lastName' => ''],
            ['firstName' => 'Florian', 'lastName' => ''],
            ['firstName' => 'Luca', 'lastName' => ''],
            ['firstName' => 'Thalie', 'lastName' => ''],
        ];

        foreach ($newCoachesData as $coachData) {
            $key = '' !== $coachData['lastName'] ? $coachData['firstName'] . ' ' . $coachData['lastName'] : $coachData['firstName'];
            $existing = $manager->getRepository(Coach::class)->findOneBy([
                'clubId' => $club->getId(),
                'firstName' => $coachData['firstName'],
            ]);
            if ($existing instanceof Coach) {
                $coaches[$key] = $existing;
            } else {
                $coach = new Coach;
                $coach->setClubId($club->getId());
                $coach->setSeasonId($season->getId());
                $coach->setFirstName($coachData['firstName']);
                $coach->setLastName($coachData['lastName']);
                $coach->setIsActive(true);
                $manager->persist($coach);
                $coaches[$key] = $coach;
            }
        }
        $manager->flush();

        // Extract typed coach references for PHPStan level 8
        $coachMaxime = $coaches['Maxime'];
        $coachMara = $coaches['Mara'];
        $coachEmerick = $coaches['Emerick'];
        $coachNicoPatin = $coaches['Nico Patin'];
        $coachEnzo = $coaches['Enzo'];
        $coachThomas = $coaches['Thomas'];
        $coachFlo = $coaches['Flo'];
        $coachChris = $coaches['Chris'];
        $coachMarlon = $coaches['Marlon'];
        $coachLionel = $coaches['Lionel'];
        $coachNicolasBarilleau = $coaches['Nicolas Barilleau'];
        $coachInes = $coaches['Ines'];
        $coachFlorian = $coaches['Florian'];
        $coachLuca = $coaches['Luca'];
        $coachThalie = $coaches['Thalie'];

        // --- Existing TeamCoach links ---
        $teamCoachLinks = [
            ['coach' => $coachMaxime, 'team' => $sm1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMara, 'team' => $sf2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEmerick, 'team' => $sf1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachNicoPatin, 'team' => $sm2, 'role' => TeamCoachRole::MAIN],
        ];

        foreach ($teamCoachLinks as $link) {
            $existing = $manager->getRepository(TeamCoach::class)->findOneBy([
                'teamId' => $link['team']->getId(),
                'coachId' => $link['coach']->getId(),
                'role' => $link['role'],
            ]);
            if (null === $existing) {
                $teamCoach = new TeamCoach;
                $teamCoach->setClubId($club->getId());
                $teamCoach->setSeasonId($season->getId());
                $teamCoach->setTeamId($link['team']->getId());
                $teamCoach->setCoachId($link['coach']->getId());
                $teamCoach->setRole($link['role']);
                $teamCoach->setIsRequired(true);
                $manager->persist($teamCoach);
            }
        }

        // ============================================================
        // SECTION 6 — NEW TEAM-COACH LINKS
        // ============================================================
        $newTeamCoachLinks = [
            ['coach' => $coachEnzo, 'team' => $u18f1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEnzo, 'team' => $u13f1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThomas, 'team' => $u21m1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThomas, 'team' => $u15m1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachFlo, 'team' => $sm3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachChris, 'team' => $sm4, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMarlon, 'team' => $u21m2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachLionel, 'team' => $sf3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachNicolasBarilleau, 'team' => $u18m1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachInes, 'team' => $u18m2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachFlorian, 'team' => $u18f3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachLuca, 'team' => $u15m2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThalie, 'team' => $u15f3, 'role' => TeamCoachRole::MAIN],
        ];

        foreach ($newTeamCoachLinks as $link) {
            $existing = $manager->getRepository(TeamCoach::class)->findOneBy([
                'teamId' => $link['team']->getId(),
                'coachId' => $link['coach']->getId(),
                'role' => $link['role'],
            ]);
            if (null === $existing) {
                $teamCoach = new TeamCoach;
                $teamCoach->setClubId($club->getId());
                $teamCoach->setSeasonId($season->getId());
                $teamCoach->setTeamId($link['team']->getId());
                $teamCoach->setCoachId($link['coach']->getId());
                $teamCoach->setRole($link['role']);
                $teamCoach->setIsRequired(true);
                $manager->persist($teamCoach);
            }
        }

        // --- Existing CoachPlayerMembership links ---
        $playerLinks = [
            ['coach' => $coachMaxime, 'team' => $sm2],
            ['coach' => $coachMara, 'team' => $sm2],
            ['coach' => $coachEmerick, 'team' => $sm2],
        ];

        foreach ($playerLinks as $link) {
            $existing = $manager->getRepository(CoachPlayerMembership::class)->findOneBy([
                'coachId' => $link['coach']->getId(),
                'teamId' => $link['team']->getId(),
            ]);
            if (null === $existing) {
                $membership = new CoachPlayerMembership;
                $membership->setClubId($club->getId());
                $membership->setSeasonId($season->getId());
                $membership->setCoachId($link['coach']->getId());
                $membership->setTeamId($link['team']->getId());
                $membership->setIsActive(true);
                $manager->persist($membership);
            }
        }

        // ============================================================
        // SECTION 7 — NEW COACH-PLAYER MEMBERSHIPS
        // ============================================================
        $newPlayerLinks = [
            ['coach' => $coachEnzo, 'team' => $sm1],
            ['coach' => $coachThomas, 'team' => $sm3],
            ['coach' => $coachNicolasBarilleau, 'team' => $sm2],
            ['coach' => $coachInes, 'team' => $sf2],
            ['coach' => $coachLuca, 'team' => $sm1],
            ['coach' => $coachThalie, 'team' => $sf3],
        ];

        foreach ($newPlayerLinks as $link) {
            $existing = $manager->getRepository(CoachPlayerMembership::class)->findOneBy([
                'coachId' => $link['coach']->getId(),
                'teamId' => $link['team']->getId(),
            ]);
            if (null === $existing) {
                $membership = new CoachPlayerMembership;
                $membership->setClubId($club->getId());
                $membership->setSeasonId($season->getId());
                $membership->setCoachId($link['coach']->getId());
                $membership->setTeamId($link['team']->getId());
                $membership->setIsActive(true);
                $manager->persist($membership);
            }
        }
        $manager->flush();

        // ============================================================
        // SECTION 8 — SLOT TEMPLATES (SM1 hard locks)
        // ============================================================
        $slotTemplates = [
            ['team' => $sm1, 'venue' => 'vMateo', 'day' => 2, 'startTime' => '20:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $sm1, 'venue' => 'vMateo', 'day' => 4, 'startTime' => '20:30', 'duration' => 90, 'lock' => LockLevel::HARD],
        ];

        foreach ($slotTemplates as $slotData) {
            $startTime = new DateTimeImmutable($slotData['startTime']);
            $existing = $manager->getRepository(ScheduleSlotTemplate::class)->findOneBy([
                'teamId' => $slotData['team']->getId(),
                'venueId' => $venues[$slotData['venue']]->getId(),
                'dayOfWeek' => $slotData['day'],
                'startTime' => $startTime,
            ]);
            if (!$existing instanceof ScheduleSlotTemplate) {
                $slot = new ScheduleSlotTemplate;
                $slot->setClubId($club->getId());
                $slot->setSeasonId($season->getId());
                $slot->setScheduleId($season->getId());
                $slot->setTeamId($slotData['team']->getId());
                $slot->setVenueId($venues[$slotData['venue']]->getId());
                $slot->setDayOfWeek($slotData['day']);
                $slot->setStartTime($startTime);
                $slot->setDurationMinutes($slotData['duration']);
                $slot->setLockLevel($slotData['lock']);
                $manager->persist($slot);
            }
        }
        $manager->flush();

        // ============================================================
        // SECTION 9 — CONSTRAINTS
        // ============================================================

        // 9a — ADN availability (closed all days except Wednesday)
        foreach ([1, 2, 4, 5, 6] as $closedDay) {
            $constraintName = 'ADN - Fermé jour ' . $closedDay;
            $existing = $manager->getRepository(Constraint::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $constraintName,
            ]);
            if (!$existing instanceof Constraint) {
                $c = new Constraint;
                $c->setClubId($club->getId());
                $c->setSeasonId($season->getId());
                $c->setScope(ConstraintScope::FACILITY);
                $c->setScopeTargetId($venues['vAdn']->getId());
                $c->setFamily(ConstraintFamily::FACILITY);
                $c->setRuleType(ConstraintRuleType::HARD);
                $c->setName($constraintName);
                $c->setConfig(['closedDay' => $closedDay]);
                $c->setIsActive(true);
                $manager->persist($c);
            }
        }

        // 9b — ADN open Wednesday 19h-22h30
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'ADN - Ouvert mercredi 19h-22h30',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::FACILITY);
            $c->setScopeTargetId($venues['vAdn']->getId());
            $c->setFamily(ConstraintFamily::TIME);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('ADN - Ouvert mercredi 19h-22h30');
            $c->setConfig(['onlyDay' => 3, 'startTime' => '19:00', 'endTime' => '22:30']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9c — Jeunes no training after 19h30 (HARD)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Jeunes - Fin entraînement 19h30',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::TIME);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('Jeunes - Fin entraînement 19h30');
            $c->setConfig(['maxStartTime' => '19:30', 'targetTag' => 'JEUNE']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9d — U11/U13 preferred from 17h30 (PREFERRED)
        foreach (['U11', 'U13'] as $tag) {
            $constraintName = $tag . ' - Début préféré 17h30';
            $existing = $manager->getRepository(Constraint::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $constraintName,
            ]);
            if (!$existing instanceof Constraint) {
                $c = new Constraint;
                $c->setClubId($club->getId());
                $c->setSeasonId($season->getId());
                $c->setScope(ConstraintScope::CLUB);
                $c->setScopeTargetId(null);
                $c->setFamily(ConstraintFamily::TIME);
                $c->setRuleType(ConstraintRuleType::PREFERRED);
                $c->setName($constraintName);
                $c->setConfig(['minStartTime' => '17:30', 'targetTag' => $tag]);
                $c->setIsActive(true);
                $manager->persist($c);
            }
        }

        // 9e — Jean Vilar: no girl teams (HARD)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Jean Vilar - Pas équipes féminines',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('Jean Vilar - Pas équipes féminines');
            $c->setConfig(['forbiddenVenueId' => $venues['vJeanVilar']->getId(), 'targetTag' => 'FEMININE']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9f — Matéo preferred for regional teams (PREFERRED)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Matéo - Préféré équipes régionales',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::PREFERRED);
            $c->setName('Matéo - Préféré équipes régionales');
            $c->setConfig(['preferredVenueId' => $venues['vMateo']->getId(), 'targetTag' => 'REGIONAL']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9g — De Barros Annexe preferred for departemental (PREFERRED)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'De Barros Annexe - Préféré équipes départementales',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::PREFERRED);
            $c->setName('De Barros Annexe - Préféré équipes départementales');
            $c->setConfig(['preferredVenueId' => $venues['vDebarrosAnnexe']->getId(), 'targetTag' => 'DEPARTEMENTAL']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9h — De Barros Annexe preferred for loisir (PREFERRED)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'De Barros Annexe - Préféré loisir',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::PREFERRED);
            $c->setName('De Barros Annexe - Préféré loisir');
            $c->setConfig(['preferredVenueId' => $venues['vDebarrosAnnexe']->getId(), 'targetTag' => 'LOISIR']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9i — Camus preferred for loisir teams (PREFERRED)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Camus - Préféré loisir',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::PREFERRED);
            $c->setName('Camus - Préféré loisir');
            $c->setConfig(['preferredVenueId' => $venues['vCamus']->getId(), 'targetTag' => 'LOISIR']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9j — SM3: Wednesday only (HARD DAY constraint)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'SM3 - Mercredi uniquement',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($sm3->getId());
            $c->setFamily(ConstraintFamily::DAY);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('SM3 - Mercredi uniquement');
            $c->setConfig(['preferredDays' => [3]]);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9k — SM3: start from 20h (HARD TIME constraint)
        $existing = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'SM3 - Début à partir de 20h',
        ]);
        if (!$existing instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($sm3->getId());
            $c->setFamily(ConstraintFamily::TIME);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('SM3 - Début à partir de 20h');
            $c->setConfig(['minStartTime' => '20:00']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        $manager->flush();
    }
}
