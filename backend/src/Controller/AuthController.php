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
use App\Service\TenantConnectionContext;
use App\Sport\BasketballCategoryCatalog;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private readonly RateLimiterFactory $authRegisterLimiter,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {}

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        // Rate-limit by client IP (anti-brute-force + anti-ARA-enumeration).
        if (!$this->authRegisterLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many attempts, please try again later'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $email = isset($data['email']) && \is_string($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : '';
        $firstName = isset($data['firstName']) && \is_string($data['firstName']) ? trim($data['firstName']) : '';
        $lastName = isset($data['lastName']) && \is_string($data['lastName']) ? trim($data['lastName']) : '';
        $ara = isset($data['ara']) && \is_string($data['ara']) ? strtoupper(trim($data['ara'])) : '';
        $clubName = isset($data['club_name']) && \is_string($data['club_name']) ? trim($data['club_name']) : '';

        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'A valid email is required'], 400);
        }
        if (\strlen($password) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], 400);
        }
        if ('' === $firstName || '' === $lastName) {
            return $this->json(['error' => 'First name and last name are required'], 400);
        }
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $ara)) {
            return $this->json(['error' => 'ARA must be 3-20 uppercase alphanumeric characters'], 400);
        }

        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Email already registered'], 409);
        }

        $existingClub = $this->clubRepository->findOneBy(['ffbbClubCode' => $ara]);

        // RLS: the register endpoint is anonymous, so no tenant GUC is set by the
        // listener. The WITH CHECK policies would reject the ClubUser/Season/…
        // inserts below without it — set it as soon as the club id is known, and
        // always clear it afterwards (finally: this connection serves other
        // requests in tests / long-running contexts).
        try {
            if (null !== $existingClub) {
                // Join an existing club: pending membership, awaits admin approval (no club data access yet).
                $this->entityManager->wrapInTransaction(function () use ($email, $password, $firstName, $lastName, $existingClub): void {
                    $this->tenantConnectionContext->setClubId($existingClub->getId());
                    $user = $this->createUser($email, $password, $firstName, $lastName);
                    $this->createMembership($existingClub->getId(), $user->getId(), false);
                });
                $status = 'pending';
            } else {
                // New club: creator becomes active admin, seed season + sport + categories.
                if ('' === $clubName) {
                    return $this->json(['error' => 'Club name is required to create a new club'], 400);
                }
                $this->entityManager->wrapInTransaction(function () use ($email, $password, $firstName, $lastName, $ara, $clubName): void {
                    $user = $this->createUser($email, $password, $firstName, $lastName);
                    $club = $this->createClub($clubName, $ara);
                    $this->tenantConnectionContext->setClubId($club->getId());
                    $this->createMembership($club->getId(), $user->getId(), true);
                    $this->seedNewClub($club);
                });
                $status = 'active';
            }
        } finally {
            $this->tenantConnectionContext->clear();
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            return $this->json(['error' => 'Registration failed'], 500);
        }

        return $this->json([
            'token' => $this->jwtManager->create($user),
            'membershipStatus' => $status,
            'user' => ['id' => $user->getId(), 'email' => $user->getEmail()],
        ], 201);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $clubUser = $this->clubUserRepository->findOneBy(['userId' => $user->getId()]);
        $membershipStatus = 'none';
        $club = null;
        $clubEntity = null;
        $baselineScheduleId = null;
        if (null !== $clubUser) {
            $membershipStatus = $clubUser->getIsActive() ? 'active' : 'pending';
            $clubEntity = $this->clubRepository->find($clubUser->getClubId());
            if (null !== $clubEntity) {
                $club = [
                    'id' => $clubEntity->getId(),
                    'name' => $clubEntity->getName(),
                    'onboardingCompleted' => $clubEntity->getOnboardingCompleted(),
                    'logoUrl' => $clubEntity->getLogoUrl(),
                    'accentColor' => $clubEntity->getAccentColor(),
                    'accentPalette' => $clubEntity->getAccentPalette(),
                ];
                $season = $this->entityManager->getRepository(Season::class)->findOneBy([
                    'clubId' => $clubEntity->getId(),
                    'status' => 'active',
                ]);
                $baselineScheduleId = $season?->getBaselineScheduleId();
            }
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'membershipStatus' => $membershipStatus,
            'role' => null !== $clubUser ? $clubUser->getRole() : null,
            'club' => $club,
            'baselineScheduleId' => $baselineScheduleId,
            'hasGenerated' => null !== $clubEntity && $clubEntity->getGenerationCountSeason() > 0,
        ]);
    }

    /** Update the connected user's own profile (self-only by construction — SEC-02). */
    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (isset($data['firstName']) && \is_string($data['firstName'])) {
            $firstName = trim($data['firstName']);
            if ('' === $firstName) {
                return $this->json(['error' => 'Le prénom est requis.'], 400);
            }
            $user->setFirstName($firstName);
        }
        if (isset($data['lastName']) && \is_string($data['lastName'])) {
            $lastName = trim($data['lastName']);
            if ('' === $lastName) {
                return $this->json(['error' => 'Le nom est requis.'], 400);
            }
            $user->setLastName($lastName);
        }
        if (isset($data['email']) && \is_string($data['email'])) {
            $email = strtolower(trim($data['email']));
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Adresse e-mail invalide.'], 400);
            }
            if ($email !== $user->getEmail()) {
                if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
                    return $this->json(['error' => 'Cet e-mail est déjà utilisé.'], 409);
                }
                $user->setEmail($email);
            }
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The findOneBy check above is not atomic with the flush — a
            // concurrent request could have taken the email in between.
            return $this->json(['error' => 'Cet e-mail est déjà utilisé.'], 409);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
        ]);
    }

    /** Change the connected user's password (requires the current one). */
    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $current = \is_string($data['currentPassword'] ?? null) ? $data['currentPassword'] : '';
        $new = \is_string($data['newPassword'] ?? null) ? $data['newPassword'] : '';

        if (!$this->passwordHasher->isPasswordValid($user, $current)) {
            return $this->json(['error' => 'Mot de passe actuel incorrect.'], 400);
        }
        if (\strlen($new) < 8) {
            return $this->json(['error' => 'Le nouveau mot de passe doit faire au moins 8 caractères.'], 400);
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $new));
        $this->entityManager->flush();

        return $this->json(['status' => 'ok']);
    }

    private function createUser(string $email, string $password, string $firstName, string $lastName): User
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);

        return $user;
    }

    private function createClub(string $clubName, string $ara): Club
    {
        $slug = (string) new AsciiSlugger('fr')->slug($clubName)->lower() . '-' . bin2hex(random_bytes(4));

        $club = new Club;
        $club->setName($clubName);
        $club->setSlug($slug);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(false);
        $club->setFfbbClubCode($ara);
        $this->entityManager->persist($club);

        return $club;
    }

    private function createMembership(string $clubId, string $userId, bool $isActive): void
    {
        $clubUser = new ClubUser;
        $clubUser->setClubId($clubId);
        $clubUser->setUserId($userId);
        $clubUser->setRole('admin');
        $clubUser->setIsActive($isActive);
        $this->entityManager->persist($clubUser);
    }

    private function seedNewClub(Club $club): void
    {
        $currentYear = (int) (new DateTimeImmutable)->format('Y');
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $currentYear);
        $season->setStartDate(new DateTimeImmutable($currentYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable($currentYear . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->entityManager->persist($season);

        $sport = $this->sportRepository->findOneBy(['slug' => 'basketball']);
        if (null === $sport) {
            $sport = new Sport;
            $sport->setName('Basketball');
            $sport->setSlug('basketball');
            $sport->setIsActive(true);
            $this->entityManager->persist($sport);
        }

        $categories = BasketballCategoryCatalog::categories();
        foreach ($categories as $categoryData) {
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
    }
}
