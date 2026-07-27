<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SuperAdmin;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — toute implémentation de `UserInterface` doit satisfaire le contrat de la
 * version de Symfony que le projet CIBLE, pas de celle qui traîne dans le lock.
 *
 * Ce que ça garde (P4-24, revues #306/#308) : `RemoveEraseCredentialsRector` lit
 * la version INSTALLÉE de `symfony/security-core` — transitivement en 8.0.x —
 * alors que `composer.json` vise 7.4 (`extra.symfony.require`) et que tout le
 * reste du stack y est. Il a supprimé `User::eraseCredentials()`, requise par
 * `UserInterface` en 7.x. Le code compilait… jusqu'au réalignement de la
 * dépendance : la classe n'implémente alors plus une méthode abstraite, PHP
 * fatale au chargement, et plus rien ne s'authentifie.
 *
 * ⚠ Le test ne peut PAS se contenter de réfléchir l'interface installée : en
 * 8.0.x elle ne déclare plus `eraseCredentials`, donc une telle assertion serait
 * toujours verte — elle ne prouverait rien du cas qu'on veut garder (c'était le
 * défaut de la première version de ce fichier, revue #308). On nomme donc la
 * méthode EXPLICITEMENT, pour toutes les implémentations.
 *
 * Le jour où le stack passe réellement en Symfony 8 : supprimer ce test ET le
 * `withSkip` de `rector.php` dans le même commit.
 */
#[Group('phase1')]
final class UserInterfaceContractTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function userInterfaceImplementorProvider(): iterable
    {
        // Les DEUX identités du projet. `SuperAdmin` avait été oubliée au premier
        // correctif : son absence est PIRE que celle de `User` — sans elle,
        // `SuperAdminProvider` n'est plus instanciable et c'est le conteneur
        // ENTIER qui refuse de démarrer, donc toute l'API, pas seulement /api/admin.
        yield 'club user' => [User::class];
        yield 'superadmin' => [SuperAdmin::class];
    }

    #[DataProvider('userInterfaceImplementorProvider')]
    public function testKeepsEraseCredentialsWhileTheStackTargetsSymfony7(string $class): void
    {
        self::assertTrue(
            method_exists($class, 'eraseCredentials'),
            \sprintf(
                '%s::eraseCredentials() a disparu. Elle est requise par UserInterface en Symfony 7.x : '
                . 'sans elle, un réalignement de symfony/security-core sur 7.4 rend la classe non '
                . 'chargeable (fatal au boot du conteneur, plus aucune authentification). Si le stack '
                . 'est passé en Symfony 8, retirer CE test ET le withSkip de rector.php dans le même commit.',
                $class,
            ),
        );
    }
}
