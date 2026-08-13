<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    // Longueur max documentée d'un token Turnstile. Au-delà, l'entrée est
    // pathologique par construction : on refuse SANS appeler Cloudflare, pour
    // qu'un token géant ne puisse pas fabriquer une « panne » et emprunter le
    // fail-open réservé aux vraies pannes (revue sécurité du lot).
    private const MAX_TOKEN_LENGTH = 2048;

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
     *  - VERDICT (`success:false`, token absent/trop long, ou réponse ILLISIBLE
     *    d'un Cloudflare joignable) → false, fail-CLOSED : quelqu'un a répondu ou
     *    l'entrée est pathologique — on refuse, c'est le cas nominal. Le corps
     *    illisible est classé ici et non en panne : sinon un token forgé pour
     *    faire dérailler l'échange emprunterait le fail-open à volonté.
     *  - TRANSPORT (Cloudflare injoignable, timeout) → true,
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
        if ('' === $token || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            // Aucun token, ou token pathologique → verdict d'emblée négatif, sans
            // appeler Cloudflare (fail-closed : rien à vérifier, pas une panne).
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
        } catch (TransportExceptionInterface $exception) {
            // Panne TRANSPORT authentique (injoignable, timeout) — fail-open
            // assumé, voir le docblock.
            $this->logger->warning('Turnstile siteverify unreachable — failing open on register.', ['exception' => $exception]);

            return true;
        } catch (Throwable $exception) {
            // Cloudflare a RÉPONDU mais illisible (corps non-JSON, statut exotique).
            // Fail-CLOSED : ce chemin est atteignable par une entrée forgée, il ne
            // doit pas offrir le fail-open réservé aux pannes (revue sécurité).
            $this->logger->warning('Turnstile siteverify returned an unreadable response — failing closed.', ['exception' => $exception]);

            return false;
        }
    }
}
