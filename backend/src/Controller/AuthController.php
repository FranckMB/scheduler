<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterUserDto;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\User;
use App\Repository\ClubRepository;
use App\Repository\SportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
        private readonly SportRepository $sportRepository,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload] RegisterUserDto $dto,
    ): JsonResponse {
        // Check ARA uniqueness
        $existingClub = $this->clubRepository->findOneBy(['ffbbClubCode' => $dto->ara]);
        if (null !== $existingClub) {
            return $this->json(['error' => 'ARA already registered'], 409);
        }

        // Check email uniqueness
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $dto->email]);
        if (null !== $existingUser) {
            return $this->json(['error' => 'Email already registered'], 409);
        }

        assert(null !== $dto->email && '' !== $dto->email);
        assert(null !== $dto->password && '' !== $dto->password);
        assert(null !== $dto->ara && '' !== $dto->ara);
        assert(null !== $dto->club_name && '' !== $dto->club_name);

        $this->entityManager->wrapInTransaction(function () use ($dto): void {
            $slugger = new AsciiSlugger('fr');
            $slug = (string) $slugger->slug($dto->club_name)->lower();

            // Append random suffix to ensure uniqueness
            $slug .= '-'.bin2hex(random_bytes(4));

            // Create user
            $user = new User();
            $user->setEmail($dto->email);
            $user->setFirstName('Admin');
            $user->setLastName('User');
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $dto->password));
            $this->entityManager->persist($user);

            // Create club
            $club = new Club();
            $club->setName($dto->club_name);
            $club->setSlug($slug);
            $club->setTimezone('Europe/Paris');
            $club->setLocale('fr');
            $club->setOnboardingCompleted(false);
            $club->setFfbbClubCode($dto->ara);
            $this->entityManager->persist($club);

            // Create club-user membership
            $clubUser = new ClubUser();
            $clubUser->setClubId($club->getId());
            $clubUser->setUserId($user->getId());
            $clubUser->setRole('admin');
            $clubUser->setIsActive(true);
            $this->entityManager->persist($clubUser);

            // Create default season (current year)
            $currentYear = (int) (new \DateTimeImmutable())->format('Y');
            $season = new Season();
            $season->setClubId($club->getId());
            $season->setName((string) $currentYear);
            $season->setStartDate(new \DateTimeImmutable($currentYear.'-01-01'));
            $season->setEndDate(new \DateTimeImmutable($currentYear.'-12-31'));
            $season->setStatus('active');
            $season->setTransitionData([]);
            $this->entityManager->persist($season);

            // Create default sport (basketball) if not exists
            $sport = $this->sportRepository->findOneBy(['slug' => 'basketball']);
            if (null === $sport) {
                $sport = new Sport();
                $sport->setName('Basketball');
                $sport->setSlug('basketball');
                $sport->setIsActive(true);
                $this->entityManager->persist($sport);
            }

            // Create default sport category (basket)
            $sportCategory = new SportCategory();
            $sportCategory->setClubId($club->getId());
            $sportCategory->setSportId($sport->getId());
            $sportCategory->setName('basket');
            $sportCategory->setIsCustom(false);
            $sportCategory->setSortOrder(0);
            $this->entityManager->persist($sportCategory);
        });

        // Reload user to ensure it has an ID for JWT generation
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $dto->email]);
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
}
