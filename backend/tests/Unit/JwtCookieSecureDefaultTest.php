<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — SEC-16 (audit) : en PRODUCTION, le cookie du JWT est `Secure`. Toujours.
 *
 * Trouvé par la revue sécurité de cette PR même. Le défaut « fail-closed » posé
 * dans `services.yaml` (`env(JWT_COOKIE_SECURE): 'true'`) ne s'applique que si la
 * variable n'est définie NULLE PART — or `backend/.env` **entre dans l'image de
 * production** (`.dockerignore` l'y garde délibérément, avec `.env.prod`). Un
 * `JWT_COOKIE_SECURE=false` posé là pour le confort du dev gagnait donc sur le
 * défaut, et la prod aurait envoyé le cookie de session **sans `Secure`** :
 * lisible en clair par un attaquant réseau au premier accès en http, puis
 * rejouable une heure durant — exactement la menace que SEC-16 prétendait fermer.
 *
 * Ce test lit les fichiers COMMITTÉS et vérifie les deux moitiés de l'invariant :
 * ce qui part dans l'image ne doit jamais dire `false`, et ce qui s'applique en
 * prod doit dire `true`. Le réglage de dev vit dans `.env.dev` / `.env.test`,
 * exclus de l'image ET chargés seulement pour leur `APP_ENV`.
 *
 * Ce que ce test NE couvre PAS : une variable d'environnement réelle posée à
 * `false` sur la VM (elle gagne sur tout fichier). C'est la vérification du
 * runbook, `docs/ops/deploy.md`.
 */
#[Group('phase1')]
final class JwtCookieSecureDefaultTest extends TestCase
{
    private const string VAR = 'JWT_COOKIE_SECURE';

    public function testTheFileShippedInTheProdImageNeverDisablesSecure(): void
    {
        $env = (string) file_get_contents(__DIR__ . '/../../.env');

        self::assertSame(
            0,
            preg_match('/^' . self::VAR . '\s*=\s*(0|false)\s*$/mi', $env),
            'backend/.env part dans l\'image de PRODUCTION : un ' . self::VAR . '=false y désarmerait '
            . 'le flag Secure du cookie de session en prod. Le réglage de dev appartient à .env.dev.',
        );
    }

    public function testProductionDefaultsToSecure(): void
    {
        $envProd = (string) file_get_contents(__DIR__ . '/../../.env.prod');

        self::assertSame(
            1,
            preg_match('/^' . self::VAR . '\s*=\s*(1|true)\s*$/mi', $envProd),
            'backend/.env.prod (committé, chargé quand APP_ENV=prod) doit poser ' . self::VAR . '=true — '
            . 'un déploiement dont le .env.prod de la VM n\'a pas encore la variable reste ainsi protégé.',
        );
    }

    public function testTheContainerDefaultStaysFailClosed(): void
    {
        // La ceinture, si un jour plus aucun fichier ne porte la variable.
        $services = (string) file_get_contents(__DIR__ . '/../../config/services.yaml');

        self::assertSame(
            1,
            preg_match('/env\(' . self::VAR . '\)\s*:\s*[\'"]?(1|true)[\'"]?/i', $services),
            'le défaut de services.yaml doit rester `true` : une variable absente doit produire un cookie Secure.',
        );
    }

    public function testDevAndTestKeepTheirInsecureCookieOutOfTheImage(): void
    {
        // Le pendant : le `false` doit exister QUELQUE PART pour le dev (sinon la
        // suite e2e, qui tape une origine http, ne s'authentifie plus) — mais
        // seulement dans des fichiers que l'image de prod n'emporte pas.
        $ignored = (string) file_get_contents(__DIR__ . '/../../../.dockerignore');

        foreach (['.env.dev', '.env.test'] as $file) {
            $content = (string) file_get_contents(__DIR__ . '/../../' . $file);
            self::assertSame(
                1,
                preg_match('/^' . self::VAR . '\s*=\s*(0|false)\s*$/mi', $content),
                $file . ' doit porter ' . self::VAR . '=false (origine http en dev/CI).',
            );
            self::assertStringContainsString(
                'backend/' . $file,
                $ignored,
                $file . ' doit rester EXCLU de l\'image (.dockerignore) — sinon son false suivrait en production.',
            );
        }
    }
}
