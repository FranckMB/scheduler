<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminMonitoringService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final readonly class AdminMonitoringController
{
    public function __construct(private AdminMonitoringService $monitoring) {}

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return new JsonResponse($this->monitoring->overview());
    }

    #[Route('/clubs', methods: ['GET'])]
    public function clubs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $query = $request->query->get('query');

        if ($page < 1 || $limit < 1 || $limit > 100 || (null !== $query && mb_strlen($query) > 100)) {
            return new JsonResponse(['error' => 'Invalid pagination or query parameters.'], 400);
        }

        return new JsonResponse($this->monitoring->clubs($page, $limit, $query));
    }
}
