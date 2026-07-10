<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClubRepository;
use App\Service\ManagementAccessGuard;
use App\Service\SchoolZoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot B: FFBB club-info partial update (committee, contacts, president,
 * correspondent, main venue, school zone), scoped to the caller's club (JWT
 * tenant) and management-gated (SEC-07). Dedicated partial endpoint — same
 * idiom as ClubAppearanceController — so the club screen saves these without
 * the generic Club resource's NotBlank fields. President/correspondent are
 * professional contacts (public FFBB data); no home addresses (RGPD).
 */
#[AsController]
final class ClubInfoController extends AbstractController
{
    /** body key → [setter, max length]. Free-text fields. */
    private const TEXT_FIELDS = [
        'committeeCode' => ['setCommitteeCode', 24],
        'contactPhone' => ['setContactPhone', 32],
        'address' => ['setAddress', 255],
        'correspondentName' => ['setCorrespondentName', 180],
        'correspondentPhone' => ['setCorrespondentPhone', 32],
        'presidentName' => ['setPresidentName', 180],
        'presidentPhone' => ['setPresidentPhone', 32],
        'mainVenueName' => ['setMainVenueName', 180],
        'mainVenueAddress' => ['setMainVenueAddress', 255],
    ];

    /** body key → setter for email fields (validated). */
    private const EMAIL_FIELDS = [
        'contactEmail' => 'setContactEmail',
        'correspondentEmail' => 'setCorrespondentEmail',
        'presidentEmail' => 'setPresidentEmail',
    ];

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/club/info', name: 'club_info', methods: ['PATCH'])]
    public function __invoke(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode((string) $request?->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        // Partial: only the keys present are touched. '' resets to null.
        foreach (self::TEXT_FIELDS as $key => [$setter, $max]) {
            if (!\array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->normalize($data[$key]);
            if (null !== $value && mb_strlen($value) > $max) {
                return $this->json(['error' => \sprintf('%s dépasse %d caractères.', $key, $max)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $club->{$setter}($value);
        }

        foreach (self::EMAIL_FIELDS as $key => $setter) {
            if (!\array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->normalize($data[$key]);
            if (null !== $value && (mb_strlen($value) > 180 || false === filter_var($value, \FILTER_VALIDATE_EMAIL))) {
                return $this->json(['error' => \sprintf('%s n\'est pas un email valide.', $key)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $club->{$setter}($value);
        }

        if (\array_key_exists('schoolZone', $data)) {
            $zone = $this->normalize($data['schoolZone']);
            if (null !== $zone && !\in_array($zone, SchoolZoneResolver::ZONES, true)) {
                return $this->json(['error' => 'schoolZone n\'est pas une zone valide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $club->setSchoolZone($zone);
        }

        $this->entityManager->flush();

        return $this->json([
            'committeeCode' => $club->getCommitteeCode(),
            'contactPhone' => $club->getContactPhone(),
            'contactEmail' => $club->getContactEmail(),
            'address' => $club->getAddress(),
            'correspondentName' => $club->getCorrespondentName(),
            'correspondentPhone' => $club->getCorrespondentPhone(),
            'correspondentEmail' => $club->getCorrespondentEmail(),
            'presidentName' => $club->getPresidentName(),
            'presidentPhone' => $club->getPresidentPhone(),
            'presidentEmail' => $club->getPresidentEmail(),
            'mainVenueName' => $club->getMainVenueName(),
            'mainVenueAddress' => $club->getMainVenueAddress(),
            'schoolZone' => $club->getSchoolZone(),
        ]);
    }

    /** Trim; empty string → null (explicit reset). */
    private function normalize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
