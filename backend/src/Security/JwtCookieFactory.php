<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * SEC-16 (audit) — LE cookie qui porte le JWT applicatif, en un seul endroit.
 *
 * Le jeton vivait en clair dans `localStorage` : une XSS, une extension véreuse
 * ou une console ouverte sur un poste partagé le lisait et le rejouait AILLEURS
 * pendant toute sa durée de vie. En cookie httpOnly, ce vol-là est impossible
 * (l'autorité ambiante d'une XSS reste, elle — on supprime l'exfiltration, pas
 * l'attaque : cf. docs/security/jwt-cookie.md).
 *
 * ⚠ POURQUOI CETTE CLASSE EXISTE : le jeton est émis à DEUX endroits — le
 * handler de succès de lexik (`/api/login`, via `set_cookies`) et
 * `AuthController::verifyEmail` (jeton créé à la main). Deux recettes d'attributs
 * dériveraient ; ici, `verifyEmail` et la déconnexion lisent la MÊME
 * configuration lexik que le handler, jamais une copie.
 *
 * ⚠ `secure` vient d'une VARIABLE D'ENV, pas de `$request->isSecure()` : le nginx
 * de prod écoute en 80 derrière la terminaison TLS et réécrit
 * `X-Forwarded-Proto` avec `$scheme` (`docker/frontend/nginx.prod.conf:57`), donc
 * `isSecure()` y répond FAUX — le cookie serait parti sans `Secure` en
 * production. À l'inverse, l'e2e dockerisé tape `http://frontend-dev:5173`
 * (`docker-compose.yml:59`), une origine non sûre où un cookie `Secure` ne serait
 * pas stocké du tout. Une env explicite est la seule qui dise vrai des deux côtés.
 */
final readonly class JwtCookieFactory
{
    public function __construct(
        #[Autowire(param: 'app.jwt_cookie_name')]
        private string $name,
        #[Autowire(param: 'app.jwt_cookie_path')]
        private string $path,
        #[Autowire(env: 'bool:JWT_COOKIE_SECURE')]
        private bool $secure,
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private int $ttl,
    ) {}

    public function create(string $token): Cookie
    {
        return Cookie::create($this->name)
            ->withValue($token)
            ->withExpires(time() + $this->ttl)
            ->withPath($this->path)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    /**
     * Le cookie d'EFFACEMENT — mêmes nom/chemin, sinon le navigateur en garde un
     * second et la déconnexion ne déconnecte rien. Avec un cookie httpOnly, cette
     * route serveur n'est pas un confort : c'est le SEUL moyen de se déconnecter,
     * le JS ne pouvant pas effacer ce qu'il ne voit pas.
     */
    public function clear(): Cookie
    {
        return Cookie::create($this->name)
            ->withValue('')
            ->withExpires(1)
            ->withPath($this->path)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
