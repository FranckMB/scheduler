<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * P5-3b — vérifieur Cloudflare Turnstile (preuve d'humanité sur /api/register).
 *
 * INERTE PAR DÉFAUT : sans secret configuré (TURNSTILE_SECRET vide, cas dev/test),
 * isEnabled() est faux et le register n'exige aucun token — le comportement actuel
 * reste byte-intact. Le secret n'est posé qu'en production.
 *
 * SSRF-safe, même patron que FfbbApiClient : l'URL siteverify est EN DUR (jamais
 * dérivée d'une entrée), redirections coupées (max_redirects=0), timeout serré. Le
 * token vient bien du client, mais il ne fait que voyager dans le corps d'un POST
 * vers un host fixe — aucune partie de l'entrée n'influence l'adresse appelée.
 */
final class TurnstileVerifier
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const TIMEOUT = 5.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $turnstileSecret = '',
    ) {}

    /** Turnstile n'est actif que si un secret est configuré (prod). */
    public function isEnabled(): bool
    {
        return '' !== $this->turnstileSecret;
    }

    /**
     * Vérifie un token de challenge auprès de Cloudflare. Deux régimes d'échec,
     * opposés À DESSEIN (décision fondateur D3) :
     *
     *  - VERDICT (`success:false`) → false, fail-CLOSED : Cloudflare a répondu que
     *    le token est invalide / absent / rejoué. On refuse : c'est le cas nominal.
     *  - TRANSPORT (Cloudflare injoignable, timeout, corps illisible) → true,
     *    fail-OPEN : le register EST l'entonnoir d'acquisition commerciale ; le
     *    verrouiller sur la panne d'un tiers ferait perdre des inscriptions réelles
     *    pour rien. Le risque résiduel reste borné par les protections qui, elles,
     *    ne dépendent pas de Cloudflare : rate-limit par IP du register, double
     *    vérification par e-mail (compte inactif tant que le lien n'est pas suivi)
     *    et gate d'approbation du club. Turnstile n'est qu'une couche de plus.
     *    L'incident est journalisé pour qu'une panne prolongée finisse par se voir.
     */
    public function verify(string $token, ?string $ip): bool
    {
        if ('' === $token) {
            // Aucun token → verdict d'emblée négatif, sans appeler Cloudflare
            // (fail-closed : il n'y a rien à vérifier, ce n'est pas une panne).
            return false;
        }

        try {
            $body = ['secret' => $this->turnstileSecret, 'response' => $token];
            if (null !== $ip && '' !== $ip) {
                $body['remoteip'] = $ip;
            }

            $data = $this->httpClient->request('POST', self::SITEVERIFY_URL, [
                'body' => $body,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ])->toArray(false);

            return true === ($data['success'] ?? false);
        } catch (Throwable $exception) {
            // Panne TRANSPORT uniquement (le verdict négatif, lui, passe par le
            // return ci-dessus) — fail-open assumé, voir le docblock.
            $this->logger->warning('Turnstile siteverify unreachable — failing open on register.', ['exception' => $exception]);

            return true;
        }
    }
}
