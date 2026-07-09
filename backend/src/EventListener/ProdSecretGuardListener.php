<?php

declare(strict_types=1);

namespace App\EventListener;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A16 fail-closed: the committed base `.env` ships FUNCTIONAL development secrets
 * (so `make start` works out of the box). Symfony's Dotenv load order is
 * `.env → .env.$env → .env.$env.local`, so a prod boot that forgets its untracked
 * `.env.prod.local` silently falls back to those publicly-committed dev secrets
 * (APP_SECRET drives CSRF tokens + signed URLs; the Mercure/JWT secrets gate the
 * realtime + auth channels). Documenting the overrides is not enough — this guard
 * REFUSES to serve a prod request while any critical secret still carries a known
 * development marker, turning a silent leak into a loud, early boot failure.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
final class ProdSecretGuardListener
{
    /** Substrings that only ever appear in the committed dev/test defaults. */
    private const DEV_MARKERS = ['change-me', 'change_me', 'clubscheduler_dev', 'insecure'];

    /**
     * @param non-empty-string $environment
     */
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
        #[Autowire('%env(JWT_PASSPHRASE)%')]
        private readonly string $jwtPassphrase,
        #[Autowire('%env(MERCURE_JWT_SECRET)%')]
        private readonly string $mercureSecret,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || 'prod' !== $this->environment) {
            return;
        }

        foreach (['APP_SECRET' => $this->appSecret, 'JWT_PASSPHRASE' => $this->jwtPassphrase, 'MERCURE_JWT_SECRET' => $this->mercureSecret] as $name => $value) {
            $this->assertNotDevSecret($name, $value);
        }
    }

    private function assertNotDevSecret(string $name, string $value): void
    {
        foreach (self::DEV_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                throw new RuntimeException(\sprintf('%s still holds a development placeholder in the prod environment. Set a real value in an untracked backend/.env.prod.local (or the runtime environment) before deploying.', $name));
            }
        }
    }
}
