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
    use ResolvesCurrentClubTrait;

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

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode((string) $this->requestStack->getCurrentRequest()?->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        // Partial: only the keys present are touched. A string is trimmed
        // (''→null resets); null resets; any other JSON type is rejected (422)
        // rather than silently wiping the column.
        foreach (self::TEXT_FIELDS as $key => [$setter, $max]) {
            if (!\array_key_exists($key, $data)) {
                continue;
            }
            if (!$this->isStringOrNull($data[$key])) {
                return $this->unprocessable(\sprintf('%s doit être une chaîne de caractères.', $key));
            }
            $value = $this->normalize($data[$key]);
            if (null !== $value && mb_strlen($value) > $max) {
                return $this->unprocessable(\sprintf('%s dépasse %d caractères.', $key, $max));
            }
            $club->{$setter}($value);
        }

        foreach (self::EMAIL_FIELDS as $key => $setter) {
            if (!\array_key_exists($key, $data)) {
                continue;
            }
            if (!$this->isStringOrNull($data[$key])) {
                return $this->unprocessable(\sprintf('%s doit être une chaîne de caractères.', $key));
            }
            $value = $this->normalize($data[$key]);
            if (null !== $value && (mb_strlen($value) > 180 || false === filter_var($value, \FILTER_VALIDATE_EMAIL))) {
                return $this->unprocessable(\sprintf('%s n\'est pas un email valide.', $key));
            }
            $club->{$setter}($value);
        }

        if (\array_key_exists('schoolZone', $data)) {
            if (!$this->isStringOrNull($data['schoolZone'])) {
                return $this->unprocessable('schoolZone doit être une chaîne de caractères.');
            }
            $zone = $this->normalize($data['schoolZone']);
            if (null !== $zone && !\in_array($zone, SchoolZoneResolver::ZONES, true)) {
                return $this->unprocessable('schoolZone n\'est pas une zone valide.');
            }
            $club->setSchoolZone($zone);
        }

        $this->entityManager->flush();

        // Echo the editable fields back, derived from the whitelist so the field
        // list lives in exactly one place.
        $out = ['schoolZone' => $club->getSchoolZone()];
        foreach ([...array_keys(self::TEXT_FIELDS), ...array_keys(self::EMAIL_FIELDS)] as $key) {
            $out[$key] = $club->{'get' . ucfirst($key)}();
        }

        return $this->json($out);
    }

    private function isStringOrNull(mixed $value): bool
    {
        return null === $value || \is_string($value);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Trim; empty string → null (explicit reset). */
    private function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
