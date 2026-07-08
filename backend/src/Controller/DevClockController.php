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
        // Require an explicit `at` key so a malformed/empty body can't silently
        // reset a pinned clock (which would read as a 200, not the bug it is).
        if (!\is_array($body) || !\array_key_exists('at', $body)) {
            return $this->json(['error' => 'Invalid payload: expected {"at": ISO|null}.'], Response::HTTP_BAD_REQUEST);
        }
        $at = $body['at'];

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
        // Single store read: when pinned, `now` IS the pin; only fall back to the
        // real clock when unpinned (avoids a second Redis round-trip per call).
        $pinned = $this->store->get();
        $now = $pinned ?? DateTimeImmutable::createFromInterface($this->clock->now());

        return [
            'now' => $now->format(DateTimeInterface::ATOM),
            'pinned' => null !== $pinned,
        ];
    }

    private function assertDev(): void
    {
        if (!$this->debug) {
            throw $this->createNotFoundException();
        }
    }
}
