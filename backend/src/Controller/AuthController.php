<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Basketball\FfbbCommittee;
use App\Entity\Basketball\FfbbLeague;
use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\EmailVerificationToken;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\Basketball\FfbbCommitteeRepository;
use App\Repository\Basketball\FfbbLeagueRepository;
use App\Repository\ClubRepository;
use App\Repository\ClubUserRepository;
use App\Service\AuditTrail;
use App\Service\ClubApprovalService;
use App\Service\ClubProvisioner;
use App\Service\EmailVerifier;
use App\Service\PasswordPolicy;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AuthController extends AbstractController
{
    // Version des textes acceptés au register — à INCRÉMENTER quand les CGU ou
    // la politique de confidentialité changent substantiellement, ENSEMBLE avec
    // frontend/src/features/legal/terms.ts (la version affichée à l'utilisateur
    // doit être celle qu'on estampille).
    public const TERMS_VERSION = '2026-07-11';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ClubRepository $clubRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly RateLimiterFactory $authRegisterLimiter,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly SeasonResolver $seasonResolver,
        private readonly ClockInterface $clock,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly MailerInterface $mailer,
        private readonly EmailVerifier $emailVerifier,
        private readonly RateLimiterFactory $authRegisterVerifyLimiter,
        private readonly string $frontendBaseUrl,
        private readonly FfbbLeagueRepository $ffbbLeagues,
        private readonly FfbbCommitteeRepository $ffbbCommittees,
        private readonly AuditTrail $auditTrail,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ClubProvisioner $clubProvisioner,
        private readonly ClubApprovalService $clubApprovalService,
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
        $consent = true === ($data['consent'] ?? false);

        // Validation below is HOISTED above the email lookup and depends only on the
        // submitted payload (or the ARA — public FFBB data), NEVER on whether the
        // email exists. A differing 400 would otherwise be an account-enumeration
        // oracle (A3). The success path returns an identical 202 for a fresh or an
        // already-registered email — existence is signalled only out-of-band by mail.
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'A valid email is required'], 400);
        }
        if (null !== ($passwordError = $this->passwordPolicy->validate($password))) {
            return $this->json(['error' => $passwordError], 400);
        }
        if ('' === $firstName || '' === $lastName) {
            return $this->json(['error' => 'First name and last name are required'], 400);
        }
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $ara)) {
            return $this->json(['error' => 'ARA must be 3-20 uppercase alphanumeric characters'], 400);
        }
        // RGPD : le consentement CGU/politique de confidentialité est requis —
        // validation payload-only, donc toujours enumeration-safe (A3).
        if (!$consent) {
            return $this->json(['error' => 'Vous devez accepter les CGU et la politique de confidentialité.'], 400);
        }

        $email = strtolower($email);
        $existingClub = $this->clubRepository->findOneBy(['ffbbClubCode' => $ara]);

        // club_name is required to CREATE a club. Keyed on the ARA (public), not the
        // email → still enumeration-safe: the 400 never depends on account existence.
        if (null === $existingClub && '' === $clubName) {
            return $this->json(['error' => 'Club name is required to create a new club'], 400);
        }

        // Intent captured at register time: a club NAME rides on the token ONLY when this
        // registration creates a club (new ARA). A join (existing ARA) stores null, so
        // verify can never silently promote a would-be pending member to admin if the
        // target club has since vanished.
        $intentClubName = null === $existingClub ? $clubName : null;

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existingUser) {
            if (null === $existingUser->getEmailVerifiedAt()) {
                // Re-registration of an UNVERIFIED account = recovery: the first email was
                // lost/expired and neither login nor reset can activate it. Refresh the
                // credentials + club intent and resend a fresh verification link. Same 202.
                $existingUser->setPasswordHash($this->passwordHasher->hashPassword($existingUser, $password));
                $existingUser->setFirstName($firstName);
                $existingUser->setLastName($lastName);
                $existingUser->setTermsAcceptedAt($this->clock->now());
                $existingUser->setTermsVersion(self::TERMS_VERSION);
                $rawToken = $this->emailVerifier->generateToken($existingUser, $ara, $intentClubName);
                $this->entityManager->flush();
                $this->sendVerificationEmail($request, $existingUser->getEmail(), $rawToken);
            } else {
                // Verified account: reveal nothing in the response. Spend an equivalent
                // password hash (timing) and send an out-of-band "you already have an
                // account" mail directing to login/reset.
                // Accepted residual: this branch skips the DB writes the create/recover
                // paths perform, so a fine-grained timing probe could still distinguish a
                // *verified* account. Bounded by the per-IP register rate limiter
                // (5/15min in prod) — network jitter dwarfs the sub-ms DB delta; not worth
                // faking writes for. The response body/status stay identical.
                $this->passwordHasher->hashPassword($existingUser, $password);
                $this->sendAccountExistsEmail($existingUser->getEmail());
            }

            return $this->verificationPendingResponse();
        }

        // Fresh email: create the UNVERIFIED account only. User is a global entity (no
        // club_id) so no tenant GUC is needed here — the club + seed are deferred to
        // /api/register/verify, so an unverified (possibly fake) registration never
        // materialises a tenant nor squats an ARA. The pending club intent (ffbb code
        // + name) rides on the verification token until then.
        $rawToken = '';
        $this->entityManager->wrapInTransaction(function () use ($email, $password, $firstName, $lastName, $ara, $intentClubName, &$rawToken): void {
            $user = $this->createUser($email, $password, $firstName, $lastName);
            $rawToken = $this->emailVerifier->generateToken($user, $ara, $intentClubName);
        });

        $this->sendVerificationEmail($request, $email, $rawToken);

        return $this->verificationPendingResponse();
    }

    #[Route('/api/register/verify', name: 'api_register_verify', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        if (!$this->authRegisterVerifyLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many attempts, please try again later'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        $rawToken = \is_array($data) && isset($data['token']) && \is_string($data['token']) ? $data['token'] : '';

        $token = $this->emailVerifier->resolve($rawToken);
        if (!$token instanceof EmailVerificationToken) {
            return $this->json(['error' => 'Invalid or expired verification token'], 400);
        }

        $tokenId = (int) $token->getId();
        $userId = $token->getUser()->getId();
        $ara = $token->getAra();
        // Non-null club name ⟺ this token was a CREATE (new ARA at register). A join
        // stores null; if its target club has since vanished, do NOT silently create a
        // club and make the user its admin — that would escalate above the join intent.
        $intentClubName = $token->getClubName();
        if (null === $intentClubName && null === $this->clubRepository->findOneBy(['ffbbClubCode' => $ara])) {
            return $this->json(['error' => 'The club to join no longer exists'], 409);
        }

        // Materialise the tenant now. Club-scoped inserts need the RLS GUC; set it once
        // the club id is known, always clear afterwards (finally).
        $status = 'pending';
        $pendingRequestId = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($tokenId, $userId, $ara, $intentClubName, &$status, &$pendingRequestId): void {
                // Serialize concurrent verifies of the SAME token (double-click / retry /
                // two tabs): the winner holds the write lock and consumes the row; a loser
                // then re-reads null and only resolves the (already-created) status — no
                // duplicate club/membership.
                $token = $this->entityManager->find(EmailVerificationToken::class, $tokenId, LockMode::PESSIMISTIC_WRITE);
                if (null === $token) {
                    $membership = $this->clubUserRepository->findOneBy(['userId' => $userId]);
                    $status = null !== $membership && $membership->getIsActive() ? 'active' : 'pending';

                    return;
                }

                $user = $token->getUser();
                $user->setEmailVerifiedAt($this->clock->now());
                // Re-resolve under the lock: the ARA may have been created since the outer read.
                $existingClub = $this->clubRepository->findOneBy(['ffbbClubCode' => $ara]);
                if (null !== $existingClub && $this->clubIsMemberless($existingClub->getId())) {
                    // RGPD win-back : le club existe mais n'a PLUS AUCUN membre
                    // actif (workspace purgé après effacement, seule la fiche
                    // FFBB a survécu). Un "pending" serait inapprouvable à
                    // jamais (le gate d'approbation exige un manager actif) et
                    // l'ARA unique interdirait de recréer le club → l'inscrit
                    // reprend le club directement (même confiance que la
                    // création : premier arrivé sur un ARA sans propriétaire).
                    $this->tenantConnectionContext->setClubId($existingClub->getId());
                    $this->createMembership($existingClub->getId(), $user->getId(), true);
                    $existingClub->setUnsubscribedAt(null);
                    $existingClub->setErasureScheduledAt(null);
                    // Re-seed uniquement si le workspace a réellement été purgé
                    // (repreneur PENDANT le délai de grâce → saisons intactes,
                    // un seed dupliquerait saison + catégories).
                    if ($this->clubHasNoSeason($existingClub->getId())) {
                        $this->seedNewClub($existingClub);
                    }
                    $status = 'active';
                } elseif (null !== $existingClub) {
                    $this->tenantConnectionContext->setClubId($existingClub->getId());
                    $this->createMembership($existingClub->getId(), $user->getId(), false);
                    $status = 'pending';
                } else {
                    // P3-4 (décision fondateur 2026-08-05) — anti-squatting : un ARA
                    // inconnu ne crée PLUS le club ici. La demande attend l'approbation
                    // du CLUB (mail institutionnel FFBB) ou du superadmin ; c'est
                    // ClubApprovalService::approve qui matérialisera (ClubProvisioner).
                    $request = $this->clubApprovalService->openRequest($user, $ara, (string) $intentClubName);
                    $pendingRequestId = $request->getId();
                    $status = 'club_pending';
                }
                $this->emailVerifier->consume($token);
            });
        } finally {
            $this->tenantConnectionContext->clear();
        }

        // P3-4 (le populate FFBB async — lot C — vit désormais dans
        // ClubApprovalService::approve, au moment où le club naît réellement.)
        // P3-4 : le mail « Approuver / Refuser » part APRÈS commit — le lien ne
        // doit jamais référencer une demande non persistée. Best-effort (service).
        if (null !== $pendingRequestId) {
            $request = $this->entityManager->getRepository(ClubCreationRequest::class)->find($pendingRequestId);
            $requester = $this->entityManager->getRepository(User::class)->find($userId);
            if (null !== $request && null !== $requester) {
                $this->clubApprovalService->sendApprovalEmail($request, $requester);
            }
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (null === $user) {
            return $this->json(['error' => 'Verification failed'], 500);
        }

        return $this->json([
            'token' => $this->jwtManager->create($user),
            'membershipStatus' => $status,
            'user' => ['id' => $user->getId(), 'email' => $user->getEmail()],
        ]);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $clubUser = $this->clubUserRepository->findOneBy(['userId' => $user->getId()]);
        $membershipStatus = 'none';
        $club = null;
        $clubEntity = null;
        $seasonPlan = null;
        $seasons = [];
        $currentSeasonId = null;
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
                    'accentColorDark' => $clubEntity->getAccentColorDark(),
                    'accentPalette' => $clubEntity->getAccentPalette(),
                    'schoolZone' => $clubEntity->getSchoolZone(),
                    // P2-21 lot A : vérité serveur — la modale « équipes importées »
                    // du wizard ne s'affiche QUE si l'import FFBB a réellement créé
                    // les équipes (jamais sur une saisie manuelle).
                    'ffbbTeamsImported' => null !== $clubEntity->getFfbbTeamsImportedAt(),
                ];

                // FFBB club info: management-only (the /club section is admin-only).
                // ⚠ Les contacts DIRIGEANTS (correspondant/président) et la salle
                // principale ne sont PLUS exposés (décision fondateur 2026-08-04) :
                // l'API FFBB ne les fournit pas, l'app ne les collecte plus, aucun
                // écran ne les rend — les colonnes restent en base, données intactes.
                if ($clubUser->getIsActive() && $this->clubUserRepository->isManagementRole($clubUser->getRole())) {
                    $committee = null !== $clubEntity->getCommitteeCode()
                        ? $this->ffbbCommittees->findByCode($clubEntity->getCommitteeCode())
                        : null;
                    $club += [
                        'league' => $clubEntity->getLeague(),
                        'ffbbClubCode' => $clubEntity->getFfbbClubCode(),
                        'committeeCode' => $clubEntity->getCommitteeCode(),
                        'contactPhone' => $clubEntity->getContactPhone(),
                        'contactEmail' => $clubEntity->getContactEmail(),
                        'address' => $clubEntity->getAddress(),
                        // FFBB autofill (lot C): institutional club data + the
                        // shared league/committee reference blocks ("Contacts FFBB").
                        // league/committee resolved from
                        // the FFBB club-code prefix + committeeCode.
                        'postalCode' => $clubEntity->getPostalCode(),
                        'city' => $clubEntity->getCity(),
                        'website' => $clubEntity->getWebsite(),
                        'latitude' => $clubEntity->getLatitude(),
                        'longitude' => $clubEntity->getLongitude(),
                        'ffbbCommittee' => $this->ffbbOrganisme($committee),
                        // Resolve the league through the committee's authoritative
                        // leagueCode link — not by re-deriving the club-code prefix.
                        'ffbbLeague' => $this->ffbbOrganisme(
                            null !== $committee?->getLeagueCode() ? $this->ffbbLeagues->findByCode($committee->getLeagueCode()) : null,
                        ),
                    ];
                }

                $allSeasons = $this->seasonResolver->seasonsForClub($clubEntity->getId());
                $now = $this->clock->now();
                $current = SeasonResolver::currentAmong($allSeasons, $now);
                $currentSeasonId = $current?->getId();

                // The gates (cockpit/wizard) follow the SELECTED season
                // (X-Season-Id → _season_id, validated by the listener), not
                // blindly the current one — the frontend gate code stays as-is.
                $selected = $current;
                $selectedId = $request->attributes->get('_season_id');
                if (\is_string($selectedId)) {
                    foreach ($allSeasons as $candidate) {
                        if ($candidate->getId() === $selectedId) {
                            $selected = $candidate;
                            break;
                        }
                    }
                }
                // ADR-0002 : LE calendrier de base de la saison, c'est le plan SEASON
                // et sa version choisie. Exposé ici pour que la bascule n'ait plus
                // qu'à déplacer les lecteurs — ADDITIF : les 3 champs legacy
                // ci-dessus restent la vérité tant que la bascule n'a pas eu lieu.
                if ($selected instanceof Season) {
                    $seasonPlan = $this->schedulePlanProvisioner->seasonPlanPayload($selected->getId());
                }

                foreach ($allSeasons as $season) {
                    $seasons[] = [
                        'id' => $season->getId(),
                        'name' => $season->getName(),
                        'startDate' => $season->getStartDate()->format('Y-m-d'),
                        'endDate' => $season->getEndDate()->format('Y-m-d'),
                        'isCurrent' => $season->getId() === $currentSeasonId,
                        'isReadonly' => SeasonResolver::isReadonlyAmong($season, $allSeasons, $now),
                    ];
                }
            }
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'membershipStatus' => $membershipStatus,
            'role' => null !== $clubUser ? $clubUser->getRole() : null,
            // P3-4 : sans membership, l'utilisateur peut porter une demande de
            // création en cours — le frontend affiche « demande transmise au club »
            // (et son issue) au lieu d'un état « aucun club » mensonger.
            'clubRequest' => $this->clubRequestState($user->getId(), $clubUser),
            'club' => $club,
            'seasonPlan' => $seasonPlan,
            'hasGenerated' => null !== $clubEntity && $clubEntity->getGenerationCountSeason() > 0,
            'seasons' => $seasons,
            'currentSeasonId' => $currentSeasonId,
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
        if (null !== ($passwordError = $this->passwordPolicy->validate($new))) {
            return $this->json(['error' => $passwordError], 400);
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $new));
        $this->entityManager->flush();

        return $this->json(['status' => 'ok']);
    }

    /**
     * The single, identical response for every register outcome (fresh email, taken
     * email, join or create) — byte-for-byte, so nothing distinguishes the branches.
     */
    private function verificationPendingResponse(): JsonResponse
    {
        return $this->json(['status' => 'verification_pending'], 202);
    }

    private function sendVerificationEmail(Request $request, string $email, string $rawToken): void
    {
        // FRONTEND_BASE_URL points at the browser-facing origin; fall back to the
        // request host in dev/e2e (single origin via the Vite proxy). Prod sets it.
        $base = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') : $request->getSchemeAndHttpHost();
        $link = $base . '/verify-email/' . $rawToken;

        // Swallow send failures: a 500 on this branch only would itself leak account
        // state (mirror PasswordController::forgot).
        try {
            $this->mailer->send(
                (new Email)
                    ->from('no-reply@clubscheduler.app')
                    ->to($email)
                    ->subject('Confirmez votre adresse e-mail ClubScheduler')
                    ->text("Bienvenue sur ClubScheduler !\n\nPour activer votre compte, ouvrez ce lien :\n{$link}\n\nCe lien expire dans 24 heures."),
            );
        } catch (Throwable) {
        }
    }

    private function sendAccountExistsEmail(string $email): void
    {
        try {
            $this->mailer->send(
                (new Email)
                    ->from('no-reply@clubscheduler.app')
                    ->to($email)
                    ->subject('Tentative d’inscription sur ClubScheduler')
                    ->text("Une inscription vient d’être tentée avec cette adresse, mais un compte existe déjà.\n\nConnectez-vous, ou réinitialisez votre mot de passe si vous l’avez oublié."),
            );
        } catch (Throwable) {
        }
    }

    private function createUser(string $email, string $password, string $firstName, string $lastName): User
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        // RGPD : preuve de consentement (horodatage + version des textes).
        $user->setTermsAcceptedAt($this->clock->now());
        $user->setTermsVersion(self::TERMS_VERSION);
        $this->entityManager->persist($user);
        // RGPD audit : événement GLOBAL (pas encore de tenant) — l'id seul,
        // jamais l'email (règle no-PII du journal).
        $this->auditTrail->record(AuditAction::AUTH_REGISTER, $user->getId(), null, 'User', $user->getId());

        return $user;
    }

    /**
     * Shape a league/committee reference row for /api/me (null when not yet
     * populated → the frontend shows an empty state).
     *
     * @return array{name: string, address: ?string, postalCode: ?string, city: ?string, phone: ?string, email: ?string, logoUrl: ?string}|null
     */
    private function ffbbOrganisme(FfbbLeague|FfbbCommittee|null $organisme): ?array
    {
        if (null === $organisme) {
            return null;
        }

        return [
            'name' => $organisme->getName(),
            'address' => $organisme->getAddress(),
            'postalCode' => $organisme->getPostalCode(),
            'city' => $organisme->getCity(),
            'phone' => $organisme->getPhone(),
            'email' => $organisme->getEmail(),
            'logoUrl' => $organisme->getLogoUrl(),
            'website' => $organisme->getWebsite(),
        ];
    }

    /** Aucun membre actif, tous rôles confondus (raw DBAL — club_user se lit cross-tenant). */
    private function clubIsMemberless(string $clubId): bool
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM club_user WHERE club_id = :cid AND is_active = true',
            ['cid' => $clubId],
        );

        return 0 === (int) $count;
    }

    /** Le GUC du club doit déjà être posé (season est RLS-gardée). */
    private function clubHasNoSeason(string $clubId): bool
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM season WHERE club_id = :cid',
            ['cid' => $clubId],
        );

        return 0 === (int) $count;
    }

    /**
     * L'état de la demande de création (P3-4) — la plus récente, pour que le
     * demandeur voie AUSSI un refus/une expiration (pas seulement l'attente).
     * Null dès qu'un membership existe : le club est né, la demande est histoire.
     *
     * @return array{status: string, clubName: string, ara: string, clubEmailKnown: bool}|null
     */
    private function clubRequestState(string $userId, ?object $clubUser): ?array
    {
        if (null !== $clubUser) {
            return null;
        }
        $request = $this->entityManager->getRepository(ClubCreationRequest::class)
            ->findOneBy(['userId' => $userId], ['createdAt' => 'DESC']);

        return $request instanceof ClubCreationRequest ? [
            'status' => $request->getStatus(),
            'clubName' => $request->getClubName(),
            'ara' => $request->getAra(),
            // false = mail FFBB introuvable : c'est le SUPPORT qui validera — l'écran
            // d'attente ne doit pas prétendre qu'un email est parti au club.
            'clubEmailKnown' => null !== $request->getClubEmail(),
        ] : null;
    }

    private function createMembership(string $clubId, string $userId, bool $isActive): void
    {
        $this->clubProvisioner->createMembership($clubId, $userId, $isActive);
    }

    private function seedNewClub(Club $club): void
    {
        $this->clubProvisioner->seedWorkspace($club);
    }
}
