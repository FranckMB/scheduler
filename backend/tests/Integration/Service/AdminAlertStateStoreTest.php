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
 * tait, firing→ok signale le rétablissement. API en deux temps (preview → envoi →
 * commit) : tant que commit() n'a pas eu lieu, preview() re-produit le même diff —
 * c'est ce qui garantit qu'une panne du mailer RE-TENTE l'alerte au tick suivant
 * au lieu de l'avaler (revue #257).
 *
 * Assertions FILTRÉES sur les clés `test:%` : la table est partagée (le job réel
 * peut y avoir des lignes), le test ne doit lire que les siennes.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminAlertStateStoreTest extends KernelTestCase
{
    private const KEY = 'test:probe';
    private const OTHER = 'test:other';

    private AdminAlertStateStore $store;

    public function testPreviewFiresUntilCommittedThenStaysSilentThenRecovers(): void
    {
        $alert = ['key' => self::KEY, 'message' => 'down'];

        // ok → firing : le diff annonce l'alerte…
        self::assertSame([$alert], $this->fired([$alert]));
        // …et tant que rien n'est commité (mailer en panne), il l'annonce ENCORE.
        self::assertSame([$alert], $this->fired([$alert]));

        // Envoi réussi → commit : le tick suivant se tait (firing→firing).
        $this->store->commit([$alert]);
        self::assertSame([], $this->fired([$alert]));
        self::assertSame([], $this->recovered([$alert]));

        // firing → ok : rétablissement annoncé, puis commité.
        self::assertSame([self::KEY], $this->recovered([]));
        $this->store->commit([]);
        self::assertSame([], $this->recovered([]));

        // ok → firing à nouveau : l'alerte est RÉARMÉE après un rétablissement.
        self::assertSame([$alert], $this->fired([$alert]));
    }

    public function testIndependentChecksTransitionIndependently(): void
    {
        $a = ['key' => self::KEY, 'message' => 'down'];
        $b = ['key' => self::OTHER, 'message' => 'stale'];

        $this->store->commit([$a]);
        // b entre en incident pendant que a y est déjà : seul b est annoncé.
        self::assertSame([$b], $this->fired([$a, $b]));
        $this->store->commit([$a, $b]);

        // a se rétablit, b reste rouge : seul a est signalé rétabli.
        self::assertSame([], $this->fired([$b]));
        self::assertSame([self::KEY], $this->recovered([$b]));
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

    /** @param list<array{key: string, message: string}> $alerts
     * @return list<array{key: string, message: string}> fired, restreint aux clés du test */
    private function fired(array $alerts): array
    {
        return array_values(array_filter(
            $this->store->preview($alerts)['fired'],
            static fn (array $alert): bool => str_starts_with($alert['key'], 'test:'),
        ));
    }

    /** @param list<array{key: string, message: string}> $alerts
     * @return list<string> recovered, restreint aux clés du test */
    private function recovered(array $alerts): array
    {
        return array_values(array_filter(
            $this->store->preview($alerts)['recovered'],
            static fn (string $key): bool => str_starts_with($key, 'test:'),
        ));
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
