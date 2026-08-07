<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\AdminSessionCsrf;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SEC-18 — LE contrôle CSRF admin, appliqué CENTRALEMENT.
 *
 * La console super-admin s'authentifie par SESSION (firewall stateful `/api/admin`),
 * donc par autorité ambiante : le navigateur envoie le cookie tout seul, y compris
 * sur une requête déclenchée depuis un autre site. C'est exactement la situation
 * qu'un jeton CSRF existe pour couvrir — et `AdminSessionCsrf::isValid()` le fait
 * bien, mais chaque contrôleur devait PENSER à l'appeler.
 *
 * Ce n'était pas un trou : au 2026-08-07, les quatre routes d'écriture admin
 * l'appelaient toutes. C'était un piège — le jour où quelqu'un ajoute un
 * `POST /api/admin/…` sans copier la ligne, l'endpoint naît sans protection et
 * rien ne le dit. Le défaut à corriger n'est pas l'absence d'un appel, c'est le
 * fait que la protection soit OPT-IN.
 *
 * Désormais : toute méthode NON SÛRE sous `/api/admin` exige le jeton, qu'un
 * contrôleur y pense ou non. Les appels par contrôleur restent en place et
 * deviennent une seconde barrière — les retirer serait remplacer une défense
 * par une autre, pas les ajouter.
 *
 * Priorité 6 : APRÈS le firewall (8) et le listener tenant (7). Il faut la
 * session, donc l'authentification ; et un 403 CSRF ne doit pas masquer un 401
 * d'identité, qui est le vrai diagnostic pour l'appelant.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class AdminCsrfListener
{
    private const string ADMIN_PREFIX = '/api/admin';

    /**
     * Les DEUX portes d'entrée de l'authentification admin, et rien d'autre.
     *
     * Elles sont `PUBLIC_ACCESS` (`security.yaml`) et se jouent AVANT qu'une
     * session porte un jeton : les protéger rendrait la connexion impossible.
     * Leur défense propre est ailleurs — throttle par IP, mot de passe, puis
     * TOTP obligatoire. La liste est explicite et gardée par un test : un futur
     * ajout ici doit être un geste conscient, pas un effet de bord.
     */
    private const array EXEMPT_PATHS = [
        '/api/admin/auth/password',
        '/api/admin/auth/totp',
    ];

    /** Méthodes sûres au sens RFC 9110 §9.2.1 — elles ne changent pas d'état. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(private AdminSessionCsrf $csrf) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->needsCsrf($request)) {
            return;
        }

        // Pas de session = pas de session admin à détourner : le firewall rendra
        // son 401. Rendre 403 ici masquerait le vrai motif.
        if (!$request->hasSession() || !$request->getSession()->isStarted()) {
            return;
        }

        if (!$this->csrf->isValid($request)) {
            $event->setResponse(new JsonResponse(['error' => 'Invalid CSRF token.'], 403));
        }
    }

    private function needsCsrf(Request $request): bool
    {
        if (!str_starts_with($request->getPathInfo(), self::ADMIN_PREFIX)) {
            return false;
        }
        if (\in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return false;
        }

        return !\in_array($request->getPathInfo(), self::EXEMPT_PATHS, true);
    }
}
