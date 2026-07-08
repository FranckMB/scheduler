<?php

declare(strict_types=1);

namespace App\Controller;

use App\Clock\DevClockStore;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only clock simulator: read / pin / release the app's "now". The pinned
 * instant lives in Redis (DevClockStore) so the cron-runner sees it too, and is
 * honoured app-wide because ClockInterface is aliased to SimulatedClock in dev.
 * Hard-guarded to debug environments — a 404 in prod, where nothing reads the
 * store anyway (prod keeps the native clock).
 */
#[AsController]
final class DevClockController extends AbstractController
{
    public function __construct(
        private readonly DevClockStore $store,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {}

    #[Route('/api/dev/clock', name: 'dev_clock_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $this->assertDev();

        return $this->json($this->state());
    }

    #[Route('/api/dev/clock', name: 'dev_clock_set', methods: ['POST'])]
    public function set(Request $request): JsonResponse
    {
        $this->assertDev();

        $body = json_decode($request->getContent() ?: '{}', true);
        $at = \is_array($body) ? ($body['at'] ?? null) : null;

        if (null === $at) {
            $this->store->set(null); // release → real time
        } elseif (\is_string($at) && '' !== $at) {
            try {
                $this->store->set(new DateTimeImmutable($at));
            } catch (Exception) {
                return $this->json(['error' => 'Invalid date.'], Response::HTTP_BAD_REQUEST);
            }
        } else {
            return $this->json(['error' => 'Invalid payload.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->state());
    }

    /**
     * @return array{now: string, pinned: bool}
     */
    private function state(): array
    {
        return [
            'now' => $this->clock->now()->format(DateTimeInterface::ATOM),
            'pinned' => null !== $this->store->get(),
        ];
    }

    private function assertDev(): void
    {
        if (!$this->debug) {
            throw $this->createNotFoundException();
        }
    }
}
