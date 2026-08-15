<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seed;

use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P4-84 — le seed BCCL est IDEMPOTENT : le relancer ne crée pas de doublon.
 *
 * Le « bug doublons » qui a ouvert le lot était une méprise (copies légitimes par
 * plan ADR-0002 + gymnases homonymes entre clubs — 0 doublon avec `schedule_plan_id`
 * dans la clé). Un doublon EXACT de créneau reste par ailleurs un état TOLÉRÉ que le
 * moteur déduplique (le récap de capacité en dépend — `RecapCapacityWarningTest`) :
 * rien ne l'interdit en base. Ce que le seeder garantit tient donc à sa seule PURGE
 * (`BcclSeeder`, section « VENUE TRAINING SLOTS ») : elle vide les créneaux du
 * club/saison AVANT de réinsérer, la boucle d'insertion ne contrôlant aucune
 * existence. Commenter cette purge fait DOUBLER les créneaux au second passage —
 * comptes divergents et clés de dédup vues deux fois, que ce test attrape.
 *
 * Le seeder exige la connexion SUPERUSER (il purge/insère à travers la RLS, comme
 * `make fixtures`). En test la connexion par défaut est `app_user` : on bascule
 * `DATABASE_URL` sur l'URL admin AVANT de booter, exactement comme la commande
 * `app:demo:seed-bccl` tourne sous `DATABASE_URL=$DATABASE_ADMIN_URL`.
 *
 * ⚠ PROCESSUS ISOLÉ obligatoire : DAMA épingle sa connexion statique au PREMIER
 * usager de `default` pour toute la durée du process (c'est ce qui tient sa
 * transaction ouverte d'un test à l'autre). Un autre test l'ayant ouverte en
 * `app_user`, notre bascule d'URL n'aurait plus prise. Un process neuf établit
 * la connexion superuser d'entrée.
 *
 * ⚠ ROLLBACK EXPLICITE : sur cette connexion superuser reconstruite, la
 * transaction statique de DAMA ne couvre pas nos écritures (constaté : un BCCL
 * fuyait dans la base de test partagée et cassait les tests Ffbb en aval). On
 * ouvre donc NOTRE transaction et on la rollback en `tearDown` — le seed, massif,
 * ne laisse aucune trace, et les deux passages tiennent dans la même transaction
 * (savepoints), ce qui n'ôte rien à la mesure d'idempotence.
 */
#[Group('integration')]
final class BcclSeederIdempotenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private Connection $connection;

    private BcclSeeder $seeder;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunningTheDevSeedTwiceIsStable(): void
    {
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertTrue($this->em->isOpen(), 'le premier passage garde l\'EntityManager ouvert');
        $first = $this->counts();

        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertTrue($this->em->isOpen(), 'le second passage garde l\'EntityManager ouvert');
        $second = $this->counts();

        self::assertSame($first, $second, 'un second seed dev ne change aucun compte (clubs, équipes, créneaux, réservations)');
        self::assertSame([], $this->duplicateSlots(), 'aucun créneau en doublon pour la clé (gymnase, jour, heure, saison, plan)');
    }

    /**
     * NR — les noms des contraintes semées SONT ceux que le wizard produirait (décision fondateur
     * 2026-08-15 : « on doit croire que la donnée vient de l'app »). Test de FORME, pas de contenu :
     * chaque nom suit « <cible> · <prédicat> », et une contrainte ciblant un TAG commence par
     * « Groupe » (le sélecteur de cible du wizard préfixe ainsi les groupes). La convention ne se
     * perd donc pas au fil des éditions du seed.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSeededConstraintNamesLookAppGenerated(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{name: string, config: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT name, config FROM "constraint" WHERE club_id = ?',
            [$club->getId()],
        );
        self::assertNotEmpty($rows, 'le seed pose bien des contraintes');

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            self::assertMatchesRegularExpression('/^.+ · .+$/u', $name, \sprintf('« %s » ne suit pas « <cible> · <prédicat> »', $name));

            $config = json_decode((string) $row['config'], true);
            if (\is_array($config) && isset($config['targetTag'])) {
                self::assertStringStartsWith('Groupe ', $name, \sprintf('« %s » cible un tag : le nom doit commencer par « Groupe »', $name));
            }
        }
    }

    protected function setUp(): void
    {
        $adminUrl = $_SERVER['DATABASE_ADMIN_URL'] ?? getenv('DATABASE_ADMIN_URL');
        self::assertNotFalse($adminUrl, 'DATABASE_ADMIN_URL doit être défini pour seeder en superuser');
        $_SERVER['DATABASE_URL'] = $adminUrl;
        $_ENV['DATABASE_URL'] = $adminUrl;
        putenv('DATABASE_URL=' . $adminUrl);

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->seeder = self::getContainer()->get(BcclSeeder::class);

        // Garde-fou : sans la connexion superuser, le seeder échouerait sur son
        // propre garde RLS — mais silencieusement tard. On l'affirme tôt.
        self::assertSame('clubscheduler', $this->connection->fetchOne('SELECT current_user'));

        // Notre filet de rollback (voir docblock) : tout le seed vit ici.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /** @return array{clubs:int, teams:int, slots:int, reservations:int} */
    private function counts(): array
    {
        return [
            'clubs' => $this->rowsIn('club'),
            'teams' => $this->rowsIn('team'),
            'slots' => $this->rowsIn('venue_training_slot'),
            'reservations' => $this->rowsIn('reservation'),
        ];
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * Les clés de dédup vues plus d'une fois — `schedule_plan_id` DANS la clé,
     * sinon les copies légitimes par plan (ADR-0002) passeraient pour des doublons.
     * `GROUP BY` regroupe les NULL ensemble : deux créneaux de BASE identiques
     * (plan NULL) seraient bien comptés comme un doublon.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateSlots(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT venue_id, day_of_week, start_time, season_id, schedule_plan_id, COUNT(*) AS n
             FROM venue_training_slot
             GROUP BY venue_id, day_of_week, start_time, season_id, schedule_plan_id
             HAVING COUNT(*) > 1',
        );
    }
}
