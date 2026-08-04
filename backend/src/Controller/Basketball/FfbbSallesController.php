<?php

declare(strict_types=1);

namespace App\Controller\Basketball;

use App\Controller\ResolvesCurrentClubTrait;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\ManagementAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-20 — les salles FFBB d'une commune, pour l'autocomplétion des gymnases du
 * wizard. Proxy obligatoire : le frontend n'appelle JAMAIS la FFBB (frontière
 * §2), et le mapping serveur ne relaie que les champs utiles — jamais le hit
 * brut. L'index n'est pas relié aux clubs (cadrage api-ffbb-completion-club
 * §3) : le CP est le seul axe, défaut = celui du club, surchargable (une salle
 * peut être dans la commune voisine). Management-gated (SEC-07) comme les
 * autres routes FFBB ; best-effort : 502 nommé, jamais un geste cassé.
 */
#[AsController]
final class FfbbSallesController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly FfbbApiClient $api,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/ffbb/salles', name: 'api_ffbb_salles', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $postalCode = (string) $request->query->get('postalCode', '');
        if ('' === $postalCode) {
            $clubId = $this->resolveCurrentClubId($this->requestStack);
            $postalCode = (string) (null !== $clubId ? $this->clubRepository->find($clubId)?->getPostalCode() : '');
        }
        if (1 !== preg_match('/^\d{5}$/', $postalCode)) {
            // Ni le param ni le club ne donnent un CP exploitable : liste vide,
            // pas une erreur — le wizard garde la saisie libre.
            return $this->json(['postalCode' => null, 'salles' => []]);
        }

        try {
            $hits = $this->api->searchSalles($postalCode);
        } catch (Throwable) {
            return $this->json(['error' => 'FFBB indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }

        $salles = array_values(array_filter(array_map($this->mapSalle(...), $hits), static fn (?array $salle): bool => null !== $salle));
        usort($salles, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $this->json(['postalCode' => $postalCode, 'salles' => $salles]);
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array<string, string|null>|null
     */
    private function mapSalle(array $hit): ?array
    {
        $name = \is_string($hit['libelle'] ?? null) ? trim($hit['libelle']) : '';
        if ('' === $name) {
            return null;
        }
        $carto = \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : [];
        $str = static fn (mixed $v): ?string => \is_string($v) && '' !== trim($v) ? trim($v) : null;
        // Lat/lng viennent en float du JSON Meilisearch ; Venue les stocke en
        // string (decimal) — on normalise ici, en refusant tout non-numérique.
        $num = static fn (mixed $v): ?string => \is_int($v) || \is_float($v) ? (string) $v : null;

        return [
            'name' => $name,
            'address' => $str($hit['adresse'] ?? null),
            'city' => $str($carto['ville'] ?? null),
            'externalRef' => $str($hit['numero'] ?? null),
            'latitude' => $num($carto['latitude'] ?? null),
            'longitude' => $num($carto['longitude'] ?? null),
        ];
    }
}
