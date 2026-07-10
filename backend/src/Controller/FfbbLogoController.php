<?php

declare(strict_types=1);

namespace App\Controller;

use App\Storage\LogoStorage;
use finfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the rehosted FFBB league/committee logos (lot C), mirroring the public
 * club-logo serve route (ClubLogoController::serve). Public brand assets (SEC-10
 * rationale) — GET only, no personal data. Bytes are stored under the namespaced
 * key `ffbb-{scope}-{code}` by FfbbClubPopulator.
 */
#[AsController]
final class FfbbLogoController extends AbstractController
{
    public function __construct(private readonly LogoStorage $storage) {}

    #[Route('/api/ffbb-logos/{scope}/{code}', name: 'ffbb_logo_serve', methods: ['GET'], requirements: ['scope' => 'league|committee', 'code' => '[A-Za-z0-9]{1,24}'])]
    public function serve(string $scope, string $code): Response
    {
        $bytes = $this->storage->read(\sprintf('ffbb-%s-%s', $scope, $code));
        if (null === $bytes) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
