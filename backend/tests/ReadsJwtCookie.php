<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * SEC-16 (audit) — LIRE le JWT là où il vit désormais : le cookie httpOnly.
 *
 * `/api/login` et `/api/register/verify` ne rendent plus `{"token": …}` dans le
 * corps (lexik le retire dès qu'il le pose en cookie). Les suites qui ont besoin
 * du jeton brut — pour rejouer un appel en `Bearer`, ou pour vérifier qu'une
 * identité a bien été émise — le prennent ici, en un seul endroit : autant de
 * lectures ad hoc, autant d'endroits à corriger au prochain changement.
 *
 * Le contrat du cookie lui-même (httpOnly, SameSite, chemin, corps sans jeton)
 * est gardé par `Security/JwtCookieContractTest`, pas ici.
 */
trait ReadsJwtCookie
{
    private function jwtFromCookie(KernelBrowser $client): string
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ('BEARER' === $cookie->getName()) {
                return (string) $cookie->getValue();
            }
        }

        return '';
    }
}
