<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared gate of the two FBI import endpoints (analyze + import): both are
 * club-wide (the real export is one file for the WHOLE club, cadrage P1-4 F1),
 * so the sequence is the SEC-04 one minus the team lookup:
 *   404 no resolvable club/membership → 403 non-management → 409 archived
 *   season → 409 socle not chosen → 400 missing/invalid file.
 *
 * Returns the tenant Club on success, or the error JsonResponse to relay —
 * the two controllers must answer byte-identically on every refusal.
 */
final class FixtureImportGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly Security $security,
    ) {}

    public function gate(Request $request): Club|JsonResponse
    {
        // The tenant listener resolved the club (JWT membership; a spoofed
        // header already died in 403 before reaching here).
        $clubId = $request->attributes->get('_club_id') ?? $request->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // SEC-04 semantics (mirrors ImportController): no active membership on
        // the club → 404; member but not a management role → 403.
        $user = $this->security->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;
        if (!$membership instanceof ClubUser) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        // Archived-season write refused (409) — AFTER auth so 403 wins first.
        $this->seasonAccessGuard->assertWritable($request);
        // Matches require the season's main plan validated first (cockpit 2→3).
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        return $club;
    }

    public function requireXlsxFile(Request $request): UploadedFile|JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        if ('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' !== $file->getMimeType()
            && !str_ends_with(strtolower($file->getClientOriginalName()), '.xlsx')
        ) {
            return new JsonResponse(['error' => 'Invalid file format. Only .xlsx files are accepted.'], Response::HTTP_BAD_REQUEST);
        }

        return $file;
    }
}
