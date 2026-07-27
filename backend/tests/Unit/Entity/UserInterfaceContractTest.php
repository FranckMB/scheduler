<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * NR — `User` doit satisfaire le contrat `UserInterface` de la version de Symfony
 * que le projet CIBLE, pas de celle qui traîne dans le lock.
 *
 * Ce que ça garde (P4-24, revue #306) : Rector lit la version INSTALLÉE de
 * `symfony/security-core` — transitivement en 8.0.x — alors que `composer.json`
 * vise 7.4 (`extra.symfony.require`) et que tout le reste du stack y est. Il a
 * donc supprimé `User::eraseCredentials()`, requise par `UserInterface` en 7.x.
 * Le code compilait quand même… jusqu'au jour où la dépendance est réalignée :
 * `User` n'implémente alors plus une méthode abstraite de l'interface, le
 * conteneur ne peut plus construire le user provider, et TOUTE authentification
 * tombe en 500. Aucun test ne l'aurait vu : la suite tourne avec le 8.0 installé.
 *
 * La méthode est inoffensive en 8.0 (simple méthode publique en trop), donc la
 * garder est l'option sûre dans les deux sens.
 */
#[Group('phase1')]
final class UserInterfaceContractTest extends TestCase
{
    public function testUserKeepsEraseCredentialsWhileTheStackTargetsSymfony7(): void
    {
        self::assertTrue(
            method_exists(User::class, 'eraseCredentials'),
            'User::eraseCredentials() a disparu. Elle est requise par UserInterface en Symfony 7.x : '
            . 'sans elle, un réalignement de symfony/security-core sur 7.4 rend User non instanciable '
            . '(fatal au boot du conteneur, plus aucune authentification). Si le stack est passé en '
            . 'Symfony 8, retirer CE test ET le withSkip de rector.php dans le même commit.',
        );
    }

    public function testUserImplementsTheSecurityContracts(): void
    {
        $reflection = new ReflectionClass(User::class);

        self::assertTrue($reflection->implementsInterface(UserInterface::class));
        // Toutes les méthodes abstraites de l'interface courante sont couvertes —
        // c'est ce que le conteneur exige au boot.
        foreach (new ReflectionClass(UserInterface::class)->getMethods() as $method) {
            self::assertTrue(
                $reflection->hasMethod($method->getName()),
                \sprintf('User doit implémenter UserInterface::%s()', $method->getName()),
            );
        }
    }
}
