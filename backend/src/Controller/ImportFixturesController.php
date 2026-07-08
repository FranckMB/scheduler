<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Service\FbiFixtureImporter;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * FBI fixtures import (module matchs PR-4): upload one FBI export for ONE team
 * — the target team is chosen at upload, the parser never guesses it. Security
 * sequence mirrors ImportController (SEC-04).
 */
#[AsController]
final class ImportFixturesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FbiFixtureImporter $importer,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        // Malformed id → 404 like any unknown team: a non-UUID must never reach
        // Postgres (22P02 on the native uuid column would surface as a 500).
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return $this->json(['error' => 'Team not found.'], Response::HTTP_NOT_FOUND);
        }

        // The Team lookup goes through the tenant+season filters: another club's
        // (or another season's) team is invisible → 404, no cross-tenant oracle.
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        if (!$team instanceof Team) {
            return $this->json(['error' => 'Team not found.'], Response::HTTP_NOT_FOUND);
        }

        // SEC-04 semantics (mirrors ImportController): no active membership on
        // the team's club → 404; member but not a management role → 403.
        $user = $this->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $team->getClubId())
            : null;
        if (null === $membership) {
            return $this->json(['error' => 'Team not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        // Archived-season write refused (409) — AFTER auth so 403 wins first.
        $this->seasonAccessGuard->assertWritable($request);
        // Matches require the season's main plan validated first (cockpit state 2→3).
        $this->socleGuard->assertValidated($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        if ('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' !== $file->getMimeType()
            && !str_ends_with(strtolower($file->getClientOriginalName()), '.xlsx')
        ) {
            return $this->json(['error' => 'Invalid file format. Only .xlsx files are accepted.'], Response::HTTP_BAD_REQUEST);
        }

        $club = $this->entityManager->getRepository(Club::class)->find($team->getClubId());
        if (!$club instanceof Club) {
            return $this->json(['error' => 'Team not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->importer->import((string) $file->getRealPath(), $team, $club);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous uploads of the same file: the in-memory dedupe
            // cannot see the racing request; the partial unique index wins →
            // a clean retryable 409 instead of a raw 500.
            return $this->json(['error' => 'Un import concurrent a créé les mêmes rencontres — réessayez.'], Response::HTTP_CONFLICT);
        } catch (RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Import completed.',
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ], Response::HTTP_OK);
    }
}
