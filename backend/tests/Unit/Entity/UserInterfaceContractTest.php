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
 * la version INSTALLÉE de `symfony/security-core`. Elle avait dérivé en 8.0.x
 * alors que `composer.json` vise 7.4 (`extra.symfony.require`) et que tout le
 * reste du stack y était. La règle a supprimé `User::eraseCredentials()`,
 * requise par `UserInterface` en 7.x. Le code compilait… jusqu'au réalignement
 * de la dépendance : la classe n'implémente alors plus une méthode abstraite,
 * PHP fatale au chargement, et plus rien ne s'authentifie.
 *
 * Depuis P4-31, `security-core` est revenue en 7.4 et la règle n'est plus
 * enregistrée du tout — le `withSkip` de `rector.php` a donc été retiré, il ne
 * protégeait plus rien. Ce test reste le SEUL garde : il attrape la
 * conséquence, `SymfonyStackAlignmentTest` attrape la cause.
 *
 * ⚠ Le test ne peut PAS se contenter de réfléchir l'interface installée : en
 * 8.0.x elle ne déclare plus `eraseCredentials`, donc une telle assertion serait
 * toujours verte — elle ne prouverait rien du cas qu'on veut garder (c'était le
 * défaut de la première version de ce fichier, revue #308). On nomme donc la
 * méthode EXPLICITEMENT, pour toutes les implémentations.
 *
 * ⚠ Si ce test rougit, le réflexe DANGEREUX est de le supprimer. Un essai de
 * montée en 8.x suffit à faire disparaître la méthode (Rector la retire dès que
 * l'installé est ≥ 8.0) ; supprimer le test à ce moment-là fait perdre le garde,
 * et le retour en 7.4 — la politique en vigueur jusqu'à la LTS 8.4 — rend alors
 * `User` et `SuperAdmin` non chargeables, conteneur fatal au boot. Le remède est
 * de RESTAURER la méthode. Ce test ne se retire que le jour où le stack est
 * DÉFINITIVEMENT en 8.x, `extra.symfony.require` compris.
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
                . 'sans elle, la classe n’est plus chargeable (fatal au boot du conteneur, plus aucune '
                . 'authentification). Remède : RESTAURER la méthode — ne pas supprimer ce test. '
                . 'Elle a probablement été retirée par `composer rector` après une dérive de '
                . 'symfony/security-core en 8.0.x ; vérifier avec SymfonyStackAlignmentTest.',
                $class,
            ),
        );
    }
}
