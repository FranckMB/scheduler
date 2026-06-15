<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\User;
use App\Repository\ClubRepository;
use App\Repository\ClubUserRepository;
use App\Repository\SportRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ClubRepository $clubRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SportRepository $sportRepository,
    ) {}

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $email = isset($data['email']) && \is_string($data['email']) ? trim($data['email']) : null;
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : null;
        $ara = isset($data['ara']) && \is_string($data['ara']) ? strtoupper(trim($data['ara'])) : null;
        $clubName = isset($data['club_name']) && \is_string($data['club_name']) ? trim($data['club_name']) : null;

        // Validate required fields
        if (null === $email || '' === $email) {
            return $this->json(['error' => 'Email is required'], 400);
        }

        if (null === $password || '' === $password) {
            return $this->json(['error' => 'Password is required'], 400);
        }

        if (null === $ara || '' === $ara) {
            return $this->json(['error' => 'ARA is required'], 400);
        }

        if (null === $clubName || '' === $clubName) {
            return $this->json(['error' => 'Club name is required'], 400);
        }

        // Validate email format
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address'], 400);
        }

        // Validate ARA format
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $ara)) {
            return $this->json(['error' => 'ARA must be 3-20 uppercase alphanumeric characters'], 400);
        }

        // Validate password strength
        if (\strlen($password) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], 400);
        }

        // Check ARA uniqueness
        $existingClub = $this->clubRepository->findOneBy(['ffbbClubCode' => $ara]);
        if (null !== $existingClub) {
            return $this->json(['error' => 'ARA already registered'], 409);
        }

        // Check email uniqueness
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existingUser) {
            return $this->json(['error' => 'Email already registered'], 409);
        }

        $this->entityManager->wrapInTransaction(function () use ($email, $password, $ara, $clubName): void {
            $slugger = new AsciiSlugger('fr');
            $slug = (string) $slugger->slug($clubName)->lower();

            // Append random suffix to ensure uniqueness
            $slug .= '-' . bin2hex(random_bytes(4));

            // Create user
            $user = new User;
            $user->setEmail($email);
            $user->setFirstName('Admin');
            $user->setLastName('User');
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $this->entityManager->persist($user);

            // Create club
            $club = new Club;
            $club->setName($clubName);
            $club->setSlug($slug);
            $club->setTimezone('Europe/Paris');
            $club->setLocale('fr');
            $club->setOnboardingCompleted(false);
            $club->setFfbbClubCode($ara);
            $this->entityManager->persist($club);

            // Create club-user membership
            $clubUser = new ClubUser;
            $clubUser->setClubId($club->getId());
            $clubUser->setUserId($user->getId());
            $clubUser->setRole('admin');
            $clubUser->setIsActive(true);
            $this->entityManager->persist($clubUser);

            // Create default season (current year)
            $currentYear = (int) (new DateTimeImmutable)->format('Y');
            $season = new Season;
            $season->setClubId($club->getId());
            $season->setName((string) $currentYear);
            $season->setStartDate(new DateTimeImmutable($currentYear . '-08-01'));
            $season->setEndDate(new DateTimeImmutable($currentYear . '-07-15'));
            $season->setStatus('active');
            $season->setTransitionData([]);
            $this->entityManager->persist($season);

            // Create default sport (basketball) if not exists
            $sport = $this->sportRepository->findOneBy(['slug' => 'basketball']);
            if (null === $sport) {
                $sport = new Sport;
                $sport->setName('Basketball');
                $sport->setSlug('basketball');
                $sport->setIsActive(true);
                $this->entityManager->persist($sport);
            }

            // Create default sport categories for basketball
            $categories = [
                ['name' => 'U7', 'ageMin' => 6, 'ageMax' => 7, 'sortOrder' => 0],
                ['name' => 'U9', 'ageMin' => 8, 'ageMax' => 9, 'sortOrder' => 1],
                ['name' => 'U11', 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 2],
                ['name' => 'U13', 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 3],
                ['name' => 'U15', 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 4],
                ['name' => 'U18', 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 5],
                ['name' => 'U21', 'ageMin' => 19, 'ageMax' => 21, 'sortOrder' => 6],
                ['name' => 'Seniors M', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 7],
                ['name' => 'Seniors F', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 8],
            ];

            foreach ($categories as $categoryData) {
                $existingCategory = $this->entityManager->getRepository(SportCategory::class)->findOneBy([
                    'clubId' => $club->getId(),
                    'name' => $categoryData['name'],
                ]);

                if (null !== $existingCategory) {
                    continue;
                }

                $sportCategory = new SportCategory;
                $sportCategory->setClubId($club->getId());
                $sportCategory->setSportId($sport->getId());
                $sportCategory->setName($categoryData['name']);
                $sportCategory->setAgeMin($categoryData['ageMin']);
                $sportCategory->setAgeMax($categoryData['ageMax']);
                $sportCategory->setIsCustom(false);
                $sportCategory->setSortOrder($categoryData['sortOrder']);
                $this->entityManager->persist($sportCategory);
            }
        });

        // Reload user to ensure it has an ID for JWT generation
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            return $this->json(['error' => 'Registration failed'], 500);
        }

        $token = $this->jwtManager->create($user);

        return $this->json([
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
            ],
        ], 201);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $clubUser = $this->clubUserRepository->findOneBy(['userId' => $user->getId(), 'isActive' => true]);
        $club = null;
        $clubEntity = null;
        if (null !== $clubUser) {
            $clubEntity = $this->clubRepository->find($clubUser->getClubId());
            if (null !== $clubEntity) {
                $club = ['id' => $clubEntity->getId(), 'name' => $clubEntity->getName()];
            }
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'club' => $club,
            'hasGenerated' => null !== $clubEntity && $clubEntity->getGenerationCountSeason() > 0,
        ]);
    }
}
