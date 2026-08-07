<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * SEC-16 (audit) — REPARTIR D'UN NAVIGATEUR NEUF avant d'endosser une identité.
 *
 * Depuis que le JWT est un cookie, browser-kit le rejoue tout seul d'une requête
 * à la suivante : une suite qui inscrit DEUX comptes envoyait le second
 * `POST /api/register` signé du PREMIER. L'API l'authentifiait, le quota par
 * utilisateur (SEC-11) comptait ces appels sur le compte précédent, et
 * l'inscription rendait 429 — l'utilisateur n'était jamais créé, et l'échec
 * remontait dix lignes plus loin en « user null ».
 *
 * Ce n'est pas une bizarrerie du harnais : un vrai navigateur ferait pareil.
 * Ce que le harnais ne faisait pas, c'est ce qu'un vrai parcours fait — changer
 * de session. D'où cette règle, nommée et appelée là où l'identité change, plutôt
 * qu'un `clear()` recopié au petit bonheur.
 */
trait StartsFreshBrowserSession
{
    private function startFreshBrowserSession(KernelBrowser $client): void
    {
        $client->getCookieJar()->clear();
    }
}
