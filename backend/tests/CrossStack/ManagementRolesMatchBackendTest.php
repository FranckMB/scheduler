<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Enum\ClubRole;
use App\Repository\ClubUserRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-32 — les rôles de gestion sont déclarés des deux côtés de la frontière.
 *
 * Le backend en est le juge (`ManagementAccessGuard`, SEC-07) ; le frontend en garde un
 * miroir pour ne pas OFFRIR un geste voué au 403. Le miroir est donc légitime — mais il
 * était réécrit inline dans deux écrans, et rien ne le reliait à sa source.
 *
 * ⚠ **Le sens dangereux est l'AJOUT.** Un rôle de gestion ajouté côté serveur et oublié
 * côté frontend : les écrans continuent de **masquer** une capacité que le backend autorise.
 * La fonctionnalité existe, elle est invisible, et **rien ne le signale** — aucune erreur, le
 * bouton n'est simplement pas là. Le retrait, lui, est bruyant : l'écran offre un geste que
 * l'API refuse, et le 403 se voit.
 */
#[Group('contract')]
final class ManagementRolesMatchBackendTest extends TestCase
{
    private const string FRONT_ROLES = __DIR__ . '/../../../frontend/src/shared/lib/roles.ts';

    public function testTheFrontendMirrorsTheBackendRoles(): void
    {
        /** @var list<string> $backend */
        $backend = new ReflectionClass(ClubUserRepository::class)->getConstant('MANAGEMENT_ROLES');
        sort($backend);

        $source = file_get_contents(self::FRONT_ROLES);
        self::assertIsString($source, \sprintf('Illisible : %s', self::FRONT_ROLES));

        $found = preg_match('/MANAGEMENT_ROLES = \[(.*?)\]/s', $source, $matches);
        self::assertSame(1, $found, 'La liste des rôles a disparu du frontend — si elle a changé de forme, mettre ce test à jour plutôt que de le retirer.');

        preg_match_all('/"([a-z_]+)"/', $matches[1], $front);
        $frontRoles = $front[1];
        sort($frontRoles);

        self::assertSame($backend, $frontRoles, \sprintf(
            "Le miroir frontend des rôles de gestion a dérivé du backend.\n"
            . "Backend : %s\nFrontend : %s\n\n"
            . "Un rôle ajouté côté serveur et oublié côté écran fait MASQUER une capacité que\n"
            . 'l\'API autorise : la fonctionnalité existe, elle est invisible, et rien ne le dit.',
            implode(', ', $backend),
            implode(', ', $frontRoles),
        ));
    }

    /**
     * P1-1 (PR C) — les rôles ASSIGNABLES sont déclarés des deux côtés : l'enum PHP
     * `ClubRole` (juge : toute écriture de rôle s'y valide, 422 sinon) et la liste
     * `ASSIGNABLE_ROLES` du frontend (ce qu'un écran d'approbation/changement PROPOSE).
     * Les deux doivent coïncider DANS LES DEUX SENS : un rôle assignable ajouté côté
     * serveur mais oublié à l'écran est invisible ; un rôle proposé à l'écran mais
     * absent de l'enum part au 422.
     */
    public function testTheFrontendAssignableRolesMirrorTheClubRoleEnum(): void
    {
        $enum = array_map(static fn (ClubRole $role): string => $role->value, ClubRole::cases());
        sort($enum);

        $source = file_get_contents(self::FRONT_ROLES);
        self::assertIsString($source, \sprintf('Illisible : %s', self::FRONT_ROLES));

        $found = preg_match('/ASSIGNABLE_ROLES = \[(.*?)\]/s', $source, $matches);
        self::assertSame(1, $found, 'La liste ASSIGNABLE_ROLES a disparu du frontend — si elle a changé de forme, mettre ce test à jour plutôt que de le retirer.');

        preg_match_all('/"([a-z_]+)"/', $matches[1], $front);
        $frontRoles = $front[1];
        sort($frontRoles);

        self::assertSame($enum, $frontRoles, \sprintf(
            "Les rôles assignables du frontend ont dérivé de l'enum ClubRole.\n"
            . "Backend (ClubRole) : %s\nFrontend (ASSIGNABLE_ROLES) : %s",
            implode(', ', $enum),
            implode(', ', $frontRoles),
        ));
    }
}
