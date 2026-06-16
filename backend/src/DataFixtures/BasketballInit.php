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
            ['name' => 'U5', 'gender' => Gender::MIXTE, 'ageMin' => 3, 'ageMax' => 5, 'sortOrder' => -1],
            ['name' => 'U7', 'gender' => Gender::MIXTE, 'ageMin' => 6, 'ageMax' => 7, 'sortOrder' => 0],
            ['name' => 'U9M', 'gender' => Gender::M, 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 1],
            ['name' => 'U9F', 'gender' => Gender::F, 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 4],
            ['name' => 'U11M', 'gender' => Gender::M, 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 5],
            ['name' => 'U11F', 'gender' => Gender::F, 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 6],
            ['name' => 'U13M', 'gender' => Gender::M, 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 7],
            ['name' => 'U13F', 'gender' => Gender::F, 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 8],
            ['name' => 'U15M', 'gender' => Gender::M, 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 9],
            ['name' => 'U15F', 'gender' => Gender::F, 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 10],
            ['name' => 'U18M', 'gender' => Gender::M, 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 11],
            ['name' => 'U18F', 'gender' => Gender::F, 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 12],
            ['name' => 'U21M', 'gender' => Gender::M, 'ageMin' => 19, 'ageMax' => 21, 'sortOrder' => 13],
            ['name' => 'Senior M', 'gender' => Gender::M, 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 14],
            ['name' => 'Senior F', 'gender' => Gender::F, 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 15],
            ['name' => 'Vétéran', 'gender' => null, 'ageMin' => 35, 'ageMax' => 99, 'sortOrder' => 16],
            ['name' => 'Loisir', 'gender' => null, 'ageMin' => null, 'ageMax' => null, 'sortOrder' => 17],
            ['name' => 'Baby basket', 'gender' => null, 'ageMin' => null, 'ageMax' => null, 'sortOrder' => 18],
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

        // --- Fetch new youth categories ---
        $u5 = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U5']);
        \assert($u5 instanceof SportCategory);
        $u7 = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U7']);
        \assert($u7 instanceof SportCategory);
        $u9M = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U9M']);
        \assert($u9M instanceof SportCategory);
        $u9F = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U9F']);
        \assert($u9F instanceof SportCategory);
        $u11M = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U11M']);
        \assert($u11M instanceof SportCategory);
        $u11F = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U11F']);
        \assert($u11F instanceof SportCategory);
        $u13M = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => 'U13M']);
        \assert($u13M instanceof SportCategory);

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
            ['name' => 'JDR', 'var' => 'vJdr'],
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
        // SECTION — VENUE AVAILABILITIES (per-venue specific hours)
        // ============================================================
        // Delete all existing VenueAvailability for this club before creating per-venue ones
        // This ensures no stale generic defaults remain after a partial re-run
        $existingVAs = $manager->getRepository(VenueAvailability::class)->findBy(['clubId' => $club->getId()]);
        foreach ($existingVAs as $va) {
            $manager->remove($va);
        }
        $manager->flush();

        // Per-venue availabilities — dayOfWeek: 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat
        // IMPORTANT: Armand Wednesday has TWO separate slots (morning + afternoon)
        // so the solver cannot schedule anything between 12:00 and 16:00.
        $perVenueAvailabilities = [
            // Debarros
            ['venue' => 'vDebarros', 'day' => 1, 'start' => '19:00', 'end' => '20:30'],
            ['venue' => 'vDebarros', 'day' => 2, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vDebarros', 'day' => 3, 'start' => '16:00', 'end' => '22:30'],
            ['venue' => 'vDebarros', 'day' => 4, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vDebarros', 'day' => 5, 'start' => '17:30', 'end' => '22:30'],
            // De Barros Annexe (preferred for departemental/loisir)
            ['venue' => 'vDebarrosAnnexe', 'day' => 2, 'start' => '19:00', 'end' => '20:30'],
            ['venue' => 'vDebarrosAnnexe', 'day' => 3, 'start' => '19:00', 'end' => '22:30'],
            ['venue' => 'vDebarrosAnnexe', 'day' => 4, 'start' => '20:30', 'end' => '22:30'],
            ['venue' => 'vDebarrosAnnexe', 'day' => 5, 'start' => '19:00', 'end' => '20:30'],
            // Camus (reserved exclusively for loisir teams)
            ['venue' => 'vCamus', 'day' => 2, 'start' => '20:15', 'end' => '22:00'],
            ['venue' => 'vCamus', 'day' => 4, 'start' => '20:15', 'end' => '22:00'],
            ['venue' => 'vCamus', 'day' => 5, 'start' => '20:15', 'end' => '22:00'],
            // Tonkin
            ['venue' => 'vTonkin', 'day' => 1, 'start' => '19:00', 'end' => '20:15'],
            ['venue' => 'vTonkin', 'day' => 3, 'start' => '16:00', 'end' => '22:30'],
            // Jean Vilar (no female teams allowed)
            ['venue' => 'vJeanVilar', 'day' => 2, 'start' => '18:00', 'end' => '22:30'],
            ['venue' => 'vJeanVilar', 'day' => 4, 'start' => '18:00', 'end' => '22:30'],
            // Matéo (main training venue, Saturday morning for Baby/Micro)
            ['venue' => 'vMateo', 'day' => 1, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vMateo', 'day' => 2, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vMateo', 'day' => 3, 'start' => '16:00', 'end' => '22:30'],
            ['venue' => 'vMateo', 'day' => 4, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vMateo', 'day' => 5, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vMateo', 'day' => 6, 'start' => '09:00', 'end' => '11:45'],
            // Gymnase Armand — Wednesday has TWO slots to block 12h-16h gap
            ['venue' => 'vArmand', 'day' => 1, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vArmand', 'day' => 2, 'start' => '17:30', 'end' => '22:30'],
            ['venue' => 'vArmand', 'day' => 3, 'start' => '09:30', 'end' => '12:00'],  // Wednesday morning
            ['venue' => 'vArmand', 'day' => 3, 'start' => '16:00', 'end' => '22:30'],  // Wednesday afternoon
            ['venue' => 'vArmand', 'day' => 4, 'start' => '17:30', 'end' => '19:00'],
            ['venue' => 'vArmand', 'day' => 5, 'start' => '17:30', 'end' => '22:30'],
            // ADN (Wednesday only — CEC + 3x3)
            ['venue' => 'vAdn', 'day' => 3, 'start' => '17:30', 'end' => '22:30'],
            // JDR (Saturday only — Academie sessions)
            ['venue' => 'vJdr', 'day' => 6, 'start' => '09:00', 'end' => '12:45'],
        ];

        foreach ($perVenueAvailabilities as $avail) {
            $va = new VenueAvailability;
            $va->setClubId($club->getId());
            $va->setSeasonId($season->getId());
            $va->setVenueId($venues[$avail['venue']]->getId());
            $va->setDayOfWeek($avail['day']);
            $va->setStartTime(new DateTimeImmutable($avail['start']));
            $va->setEndTime(new DateTimeImmutable($avail['end']));
            $manager->persist($va);
        }
        $manager->flush();

        // ============================================================
        // SECTION 3 — SPORT CATEGORIES (fetch additional ones)
        // ============================================================
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

        // ============================================================
        // SECTION 4 — NEW TEAMS
        // ============================================================
        $newTeamsData = [
            ['name' => 'SM1', 'sportCategory' => $seniorM, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 1, 'gender' => Gender::M],
            ['name' => 'SM2', 'sportCategory' => $seniorM, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'SF1', 'sportCategory' => $seniorF, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 1, 'gender' => Gender::F],
            ['name' => 'SF2', 'sportCategory' => $seniorF, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::F],
            ['name' => 'SM3', 'sportCategory' => $seniorM, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'SM4', 'sportCategory' => $seniorM, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::M],
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
            ['name' => 'U13F1', 'sportCategory' => $u13F, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U13F2', 'sportCategory' => $u13F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U13F3', 'sportCategory' => $u13F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => 'U13M1', 'sportCategory' => $u13M, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U13M2', 'sportCategory' => $u13M, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U11F1', 'sportCategory' => $u11F, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U11F2', 'sportCategory' => $u11F, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U9M1', 'sportCategory' => $u9M, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U9M2', 'sportCategory' => $u9M, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::M],
            // --- Loisir / Baby / Academie teams ---
            ['name' => 'Baby 1',                'sportCategory' => $u7,    'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Baby 2',                'sportCategory' => $u7,    'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Micro Basket',          'sportCategory' => $u5,    'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Academie U9-U11',       'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Academie U13-U15',      'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Academie U18',          'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Mercredi Shark U9-U11', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir 1',              'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir 2',              'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir 3',              'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir Feminine',       'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => '3x3',                   'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            // --- CEC Groups (joint training sessions — youth teams without individual EMB teams) ---
            // CEC Groupe 1 = joint training for U9F1 + U9F2 + U9M2 players (no individual teams exist)
            ['name' => 'CEC Groupe 1',          'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            // CEC Groupe 2 = joint training for U11F2 + U9M1 players
            ['name' => 'CEC Groupe 2',          'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            // CEC Groupe 3 = joint training for U11F1 + U11M2 players
            ['name' => 'CEC Groupe 3',          'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
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
        /** @var array<string, Team> $teams */
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
        $u13f2 = $teams['U13F2'];
        $u13f3 = $teams['U13F3'];
        $u13m1 = $teams['U13M1'];
        $u13m2 = $teams['U13M2'];
        $u11f1 = $teams['U11F1'];
        $u11f2 = $teams['U11F2'];
        $u9m1 = $teams['U9M1'];
        $u9m2 = $teams['U9M2'];
        $baby1 = $teams['Baby 1'];
        $baby2 = $teams['Baby 2'];
        $microBasket = $teams['Micro Basket'];
        $academieU9U11 = $teams['Academie U9-U11'];
        $academieU13U15 = $teams['Academie U13-U15'];
        $academieU18 = $teams['Academie U18'];
        $mercredShark = $teams['Mercredi Shark U9-U11'];
        $loisir1 = $teams['Loisir 1'];
        $loisir2 = $teams['Loisir 2'];
        $loisir3 = $teams['Loisir 3'];
        $loisirFeminine = $teams['Loisir Feminine'];
        $team3x3 = $teams['3x3'];
        $cecGroupe1 = $teams['CEC Groupe 1'];
        $cecGroupe2 = $teams['CEC Groupe 2'];
        $cecGroupe3 = $teams['CEC Groupe 3'];

        // ============================================================
        // SECTION 5 — NEW COACHES
        // ============================================================
        $newCoachesData = [
            ['firstName' => 'Maxime', 'lastName' => 'Dionnet'],
            ['firstName' => 'Mara', 'lastName' => ''],
            ['firstName' => 'Emerick', 'lastName' => ''],
            ['firstName' => 'Nico', 'lastName' => 'Patin'],
            ['firstName' => 'Enzo', 'lastName' => ''],
            ['firstName' => 'Thomas', 'lastName' => ''],
            ['firstName' => 'Flo', 'lastName' => 'Tapaunat'],
            ['firstName' => 'Chris', 'lastName' => ''],
            ['firstName' => 'Marlon', 'lastName' => ''],
            ['firstName' => 'Lionel', 'lastName' => 'Lacroute'],
            ['firstName' => 'Nicolas', 'lastName' => 'Barilleau'],
            ['firstName' => 'Ines', 'lastName' => ''],
            ['firstName' => 'Florian', 'lastName' => ''],
            ['firstName' => 'Luca', 'lastName' => 'Blanchini'],
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
        /** @var array{'Maxime Dionnet': Coach, Mara: Coach, Emerick: Coach, 'Nico Patin': Coach, Enzo: Coach, Thomas: Coach, 'Flo Tapaunat': Coach, Chris: Coach, Marlon: Coach, 'Lionel Lacroute': Coach, 'Nicolas Barilleau': Coach, Ines: Coach, Florian: Coach, 'Luca Blanchini': Coach, Thalie: Coach} $coaches */
        $coachMaxime = $coaches['Maxime Dionnet'];
        $coachMara = $coaches['Mara'];
        $coachEmerick = $coaches['Emerick'];
        $coachNicoPatin = $coaches['Nico Patin'];
        $coachEnzo = $coaches['Enzo'];
        $coachThomas = $coaches['Thomas'];
        $coachFlo = $coaches['Flo Tapaunat'];
        $coachChris = $coaches['Chris'];
        $coachMarlon = $coaches['Marlon'];
        $coachLionel = $coaches['Lionel Lacroute'];
        $coachNicolasBarilleau = $coaches['Nicolas Barilleau'];
        $coachInes = $coaches['Ines'];
        $coachFlorian = $coaches['Florian'];
        $coachLuca = $coaches['Luca Blanchini'];
        $coachThalie = $coaches['Thalie'];

        // ============================================================
        // SECTION 6 — NEW TEAM-COACH LINKS
        // ============================================================
        $newTeamCoachLinks = [
            ['coach' => $coachMaxime, 'team' => $sm1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMara, 'team' => $sf2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEmerick, 'team' => $sf1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachNicoPatin, 'team' => $sm2, 'role' => TeamCoachRole::MAIN],
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

        // ============================================================
        // SECTION 7 — NEW COACH-PLAYER MEMBERSHIPS
        // ============================================================
        $newPlayerLinks = [
            ['coach' => $coachEnzo, 'team' => $sm1],
            ['coach' => $coachLuca, 'team' => $sm1],
            ['coach' => $coachNicolasBarilleau, 'team' => $sm2],
            ['coach' => $coachMaxime, 'team' => $sm2],
            ['coach' => $coachMara, 'team' => $sm2],
            ['coach' => $coachEmerick, 'team' => $sm2],
            ['coach' => $coachThomas, 'team' => $sm3],
            ['coach' => $coachInes, 'team' => $sf2],
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

        // 9b — ADN open Wednesday 17h30-22h30 (CORRECTED from 19h)
        // Delete old constraint with wrong time if it exists
        $oldAdnConstraint = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'ADN - Ouvert mercredi 19h-22h30',
        ]);
        if ($oldAdnConstraint instanceof Constraint) {
            $manager->remove($oldAdnConstraint);
            $manager->flush();
        }
        $existingAdnOpen = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'ADN - Ouvert mercredi 17h30-22h30',
        ]);
        if (!$existingAdnOpen instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::FACILITY);
            $c->setScopeTargetId($venues['vAdn']->getId());
            $c->setFamily(ConstraintFamily::TIME);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('ADN - Ouvert mercredi 17h30-22h30');
            $c->setConfig(['onlyDay' => 3, 'startTime' => '17:30', 'endTime' => '22:30']);
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

        // 9i — Camus reserved EXCLUSIVELY for loisir teams (HARD — user confirmed "exclusivement")
        // Delete old PREFERRED constraint if it exists
        $oldCamusPreferred = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Camus - Préféré loisir',
        ]);
        if ($oldCamusPreferred instanceof Constraint) {
            $manager->remove($oldCamusPreferred);
            $manager->flush();
        }
        $existingCamusHard = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Camus - Réservé loisir exclusivement',
        ]);
        if (!$existingCamusHard instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::CLUB);
            $c->setScopeTargetId(null);
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('Camus - Réservé loisir exclusivement');
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

        // 9k — SM3: start from 20h15 at Armand exclusively (CORRECTED from 20:00)
        $oldSm3Time = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'SM3 - Début à partir de 20h',
        ]);
        if ($oldSm3Time instanceof Constraint) {
            $manager->remove($oldSm3Time);
            $manager->flush();
        }
        $existingSm3Time = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'SM3 - Début à partir de 20h15',
        ]);
        if (!$existingSm3Time instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($sm3->getId());
            $c->setFamily(ConstraintFamily::TIME);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('SM3 - Début à partir de 20h15');
            $c->setConfig(['minStartTime' => '20:15']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 9k2 — SM3: forced to Gymnase Armand on Wednesday (HARD)
        $existingSm3Venue = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'SM3 - Gymnase Armand mercredi exclusivement',
        ]);
        if (!$existingSm3Venue instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($sm3->getId());
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('SM3 - Gymnase Armand mercredi exclusivement');
            $c->setConfig(['forcedVenueId' => $venues['vArmand']->getId()]);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // ============================================================
        // SECTION 10 — ADDITIONAL SLOT TEMPLATES
        // ============================================================

        // JDR Saturday — Academie hard-locked sessions
        $additionalSlots = [
            // JDR Saturday academies
            ['team' => $academieU9U11,  'venue' => 'vJdr',   'day' => 6, 'startTime' => '09:00', 'duration' => 75,  'lock' => LockLevel::HARD],
            ['team' => $academieU13U15, 'venue' => 'vJdr',   'day' => 6, 'startTime' => '10:15', 'duration' => 75,  'lock' => LockLevel::HARD],
            ['team' => $academieU18,    'venue' => 'vJdr',   'day' => 6, 'startTime' => '11:30', 'duration' => 75,  'lock' => LockLevel::HARD],
            // Matéo Saturday morning — Baby & Micro Basket
            ['team' => $microBasket,    'venue' => 'vMateo', 'day' => 6, 'startTime' => '09:00', 'duration' => 45,  'lock' => LockLevel::HARD],
            ['team' => $baby1,          'venue' => 'vMateo', 'day' => 6, 'startTime' => '09:45', 'duration' => 60,  'lock' => LockLevel::HARD],
            ['team' => $baby2,          'venue' => 'vMateo', 'day' => 6, 'startTime' => '10:45', 'duration' => 60,  'lock' => LockLevel::HARD],
            // CEC Groupe 1 — ADN Wednesday 17:30 (ADN can be split into 3 courts)
            ['team' => $cecGroupe1,     'venue' => 'vAdn',   'day' => 3, 'startTime' => '17:30', 'duration' => 90,  'lock' => LockLevel::HARD],
        ];

        foreach ($additionalSlots as $slotData) {
            $startTime = new DateTimeImmutable($slotData['startTime']);
            $existingSlot = $manager->getRepository(ScheduleSlotTemplate::class)->findOneBy([
                'teamId' => $slotData['team']->getId(),
                'venueId' => $venues[$slotData['venue']]->getId(),
                'dayOfWeek' => $slotData['day'],
                'startTime' => $startTime,
            ]);
            if (!$existingSlot instanceof ScheduleSlotTemplate) {
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

        // ============================================================
        // SECTION 11 — NEW CONSTRAINTS
        // ============================================================

        // 11a — 3x3 team: forced to ADN on Wednesday, slot starts at 20h30
        $existing11a = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => '3x3 - ADN mercredi 20h30 exclusivement',
        ]);
        if (!$existing11a instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($team3x3->getId());
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('3x3 - ADN mercredi 20h30 exclusivement');
            $c->setConfig(['forcedVenueId' => $venues['vAdn']->getId(), 'forcedDay' => 3, 'minStartTime' => '20:30']);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 11b — Loisir Feminine: Annexe De Barros on Thursday exclusively
        $existing11b = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'Loisir Féminine - Annexe jeudi exclusivement',
        ]);
        if (!$existing11b instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($loisirFeminine->getId());
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::HARD);
            $c->setName('Loisir Féminine - Annexe jeudi exclusivement');
            $c->setConfig(['forcedVenueId' => $venues['vDebarrosAnnexe']->getId(), 'forcedDay' => 4]);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 11c — SF1: at least one training at Matéo (PREFERRED)
        $existing11c = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => 'SF1 - Minimum 1 entraînement à Matéo',
        ]);
        if (!$existing11c instanceof Constraint) {
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope(ConstraintScope::TEAM);
            $c->setScopeTargetId($sf1->getId());
            $c->setFamily(ConstraintFamily::FACILITY);
            $c->setRuleType(ConstraintRuleType::PREFERRED);
            $c->setName('SF1 - Minimum 1 entraînement à Matéo');
            $c->setConfig(['preferredVenueId' => $venues['vMateo']->getId()]);
            $c->setIsActive(true);
            $manager->persist($c);
        }

        // 11d — Jean Vilar: preferred for U15M/U18M/U21M/SM4 (PREFERRED)
        foreach ([$u15m1, $u15m2, $u18m1, $u18m2, $u21m1, $u21m2, $sm4] as $targetTeam) {
            /** @var Team $targetTeam */
            $constraintName = $targetTeam->getName() . ' - Jean Vilar préféré';
            $existingJv = $manager->getRepository(Constraint::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $constraintName,
            ]);
            if (!$existingJv instanceof Constraint) {
                $c = new Constraint;
                $c->setClubId($club->getId());
                $c->setSeasonId($season->getId());
                $c->setScope(ConstraintScope::TEAM);
                $c->setScopeTargetId($targetTeam->getId());
                $c->setFamily(ConstraintFamily::FACILITY);
                $c->setRuleType(ConstraintRuleType::PREFERRED);
                $c->setName($constraintName);
                $c->setConfig(['preferredVenueId' => $venues['vJeanVilar']->getId()]);
                $c->setIsActive(true);
                $manager->persist($c);
            }
        }

        $manager->flush();
    }
}
