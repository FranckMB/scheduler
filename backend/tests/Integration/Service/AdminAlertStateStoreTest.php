<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminAlertStateStore;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Anti-spam des alertes superadmin : ok→firing notifie UNE fois, firing→firing se
 * tait, firing→ok signale le rétablissement. C'est le contrat qui empêche à la fois
 * le silence (panne jamais signalée) et le spam (un email toutes les 10 minutes
 * pendant un incident).
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminAlertStateStoreTest extends KernelTestCase
{
    private const KEY = 'test:probe';
    private const OTHER = 'test:other';

    private AdminAlertStateStore $store;

    public function testTransitionsFireOnceThenStaySilentThenRecover(): void
    {
        $alert = ['key' => self::KEY, 'message' => 'down'];

        // ok → firing : notifié, état posé.
        $diff = $this->store->transition([$alert]);
        self::assertSame([$alert], $diff['fired']);
        self::assertSame([], $diff['recovered']);

        // firing → firing : SILENCE (pas de re-spam pendant l'incident).
        $diff = $this->store->transition([$alert]);
        self::assertSame([], $diff['fired']);
        self::assertSame([], $diff['recovered']);

        // firing → ok : rétablissement signalé, état relâché.
        $diff = $this->store->transition([]);
        self::assertSame([], $diff['fired']);
        self::assertSame([self::KEY], $diff['recovered']);

        // ok → firing à nouveau : l'alerte est RÉARMÉE après un rétablissement.
        $diff = $this->store->transition([$alert]);
        self::assertSame([$alert], $diff['fired']);
    }

    public function testIndependentChecksTransitionIndependently(): void
    {
        $a = ['key' => self::KEY, 'message' => 'down'];
        $b = ['key' => self::OTHER, 'message' => 'stale'];

        $this->store->transition([$a]);
        // b entre en incident pendant que a y est déjà : seul b est notifié.
        $diff = $this->store->transition([$a, $b]);
        self::assertSame([$b], $diff['fired']);

        // a se rétablit, b reste rouge : seul a est signalé rétabli.
        $diff = $this->store->transition([$b]);
        self::assertSame([], $diff['fired']);
        self::assertSame([self::KEY], $diff['recovered']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = self::getContainer()->get(AdminAlertStateStore::class);
        $this->admin()->executeStatement('DELETE FROM admin_alert_state WHERE check_key LIKE \'test:%\'');
    }

    protected function tearDown(): void
    {
        $this->admin()->executeStatement('DELETE FROM admin_alert_state WHERE check_key LIKE \'test:%\'');
        parent::tearDown();
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
