<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — SEC-16 : le transport d'ÉCHEC ne partage JAMAIS le stream des messages vifs.
 *
 * Un DSN redis s'écrit `redis://host:port/STREAM/GROUP`. L'ancien réglage posait le
 * groupe `failed` sur le stream `messages` — le même que le transport `async`. Dès
 * que ce groupe était matérialisé (un `messenger:setup-transports`, ou le premier
 * message réellement routé en échec), TOUT dispatch rendait 500 : le transport
 * redis refuse `delete_after_ack` quand plus d'un groupe lit le stream (« it risks
 * deleting messages before all groups could consume them »). Reproduit en dev le
 * 2026-08-07 — en prod, la première vraie panne d'un handler aurait gelé génération,
 * exports et imports d'un coup.
 *
 * Le test lit les DSN par défaut COMMITTÉS (backend/.env) : c'est la valeur que
 * tout environnement hérite s'il ne la surcharge pas — un retour en arrière dans ce
 * fichier est exactement la régression à attraper. Une surcharge `.env.prod` reste
 * possible : le runbook de déploiement porte la vérification côté ops.
 */
#[Group('phase1')]
final class MessengerTransportSeparationTest extends TestCase
{
    public function testFailureTransportUsesItsOwnRedisStream(): void
    {
        $env = (string) file_get_contents(__DIR__ . '/../../.env');

        self::assertSame(1, preg_match('/^MESSENGER_TRANSPORT_DSN=redis:\/\/[^\/]+\/([^\/\s]+)/m', $env, $async), 'DSN async redis attendu dans backend/.env');
        self::assertSame(1, preg_match('/^MESSENGER_FAILURE_TRANSPORT_DSN=redis:\/\/[^\/]+\/([^\/\s]+)/m', $env, $failed), 'DSN failure redis attendu dans backend/.env');

        self::assertNotSame(
            $async[1],
            $failed[1],
            \sprintf(
                'Le transport d\'échec lit le stream « %s » — le MÊME que les messages vifs. '
                . 'Dès que son groupe est matérialisé, tout dispatch rend 500 (delete_after_ack '
                . '+ plusieurs groupes). Donnez-lui un stream dédié (SEC-16).',
                $failed[1],
            ),
        );
    }
}
