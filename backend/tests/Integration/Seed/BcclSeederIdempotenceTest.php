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

    /**
     * P5-17 — après le seed dev, le plan SEASON du club POINTE (chosen) une version
     * COMPLETED : la transcription littérale du planning réel (90 créneaux), marquée
     * `seed-transcription`. Le club dev n'est donc plus « avant première génération » —
     * il ouvre sur le planning réel, sans jamais appeler le solveur.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedPointsSeasonPlanAtCompletedTranscription(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        $row = $this->connection->fetchAssociative(
            'SELECT s.status, s.solver_version, '
            . '(SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
            . 'FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
            . 'WHERE sp.season_id = (SELECT id FROM season WHERE club_id = ? AND name = \'2026-2027\') '
            . 'AND sp.type = \'SEASON\'',
            [$club->getId()],
        );

        self::assertNotFalse($row, 'le plan SEASON du club dev pointe une version choisie');
        self::assertSame('COMPLETED', (string) $row['status'], 'la version pointée est COMPLETED');
        self::assertSame('seed-transcription', (string) $row['solver_version'], 'la provenance est la transcription du seed');
        self::assertSame(90, (int) $row['slot_count'], 'la transcription pose exactement 90 créneaux (lundi→samedi)');
    }

    /**
     * P5-17 — chaque RÉSERVATION DE BASE seedée (pin durable HARD, plan NULL) retrouve son
     * créneau dans la transcription de SAISON pointée, verrouillé HARD (exactement ce qu'un
     * import de résultat solveur produirait). Une réservation ORPHELINE — sans créneau transcrit
     * apparié — trahit une transcription incomplète, et ce test la nomme.
     *
     * P5-13 : la clause `schedule_plan_id IS NULL` cible les seules réservations de BASE ; celles
     * des reprises sont portées par leur plan de période et vérifiées par leur propre test.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEverySeededReservationHasMatchingTranscribedSlot(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        $orphans = $this->connection->fetchAllAssociative(
            'SELECT r.team_id, r.venue_id, r.day_of_week, r.start_time FROM reservation r '
            . 'WHERE r.club_id = ? AND r.schedule_plan_id IS NULL AND NOT EXISTS ( '
            . 'SELECT 1 FROM schedule_slot_template t '
            . 'JOIN schedule_plan sp ON sp.chosen_schedule_id = t.schedule_id '
            . 'WHERE sp.type = \'SEASON\' AND sp.club_id = r.club_id '
            . 'AND t.team_id = r.team_id AND t.venue_id = r.venue_id '
            . 'AND t.day_of_week = r.day_of_week AND t.start_time = r.start_time '
            . 'AND t.lock_level = \'HARD\' )',
            [$club->getId()],
        );

        self::assertSame([], $orphans, 'chaque réservation seedée est appariée à un créneau transcrit verrouillé HARD');
    }

    /**
     * P5-17 — la transcription ne vise QUE le profil dev : le club de DÉMONSTRATION reste
     * « avant première génération » (plan SEASON sans pointeur), l'écran de démo part sur le
     * wizard/Récap comme avant.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDemoSeedLeavesSeasonPlanUnpointed(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::demo('demo-pass-transcription'));

        $chosen = $this->connection->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE club_id = ? AND type = \'SEASON\'',
            [$club->getId()],
        );

        self::assertNull(false === $chosen ? null : $chosen, 'le club de démonstration ne pointe aucun planning');
    }

    /**
     * NR — LE PLANNING RÉEL TRANSCRIT RESPECTE LES RÈGLES DURES QUE LE SEED DÉCLARE.
     *
     * Le seed fait deux choses qui peuvent se contredire : il transcrit le planning RÉEL du club
     * (P5-17, 90 créneaux relevés sur le terrain) et il déclare les contraintes du club. Ajouter
     * une règle DURE qui interdit ce que le planning réel fait rendrait la génération infaisable
     * — et on ne s'en apercevrait qu'au premier `make fixtures` suivi d'une génération, donc
     * potentiellement des jours plus tard, sur une base neuve.
     *
     * Ce test le dit tout de suite, sans moteur ni solve : pour chaque contrainte HARD de portée
     * ÉQUIPE (jour ou horaire), chaque séance transcrite de cette équipe doit la satisfaire.
     *
     * PORTÉE ASSUMÉE : les contraintes de portée CLUB (par tag) ne sont pas couvertes ici — leur
     * résolution en équipes vit dans le builder, pas dans le seed, et la garder ici dupliquerait
     * cette algèbre. Le risque que ce test cible est celui qui s'est présenté : une règle TEAM
     * ajoutée au seed d'après ce que le gestionnaire a saisi dans l'app.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTranscribedRealScheduleSatisfiesEveryHardTeamRule(): void
    {
        $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{team: string, day: int, start: string, name: string, family: string, config: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, s.day_of_week AS day, to_char(s.start_time, \'HH24:MI\') AS start, '
            . 'c.name AS name, c.family AS family, c.config::text AS config '
            . 'FROM "constraint" c '
            . 'JOIN team t ON t.id = c.scope_target_id '
            . 'JOIN schedule_slot_template s ON s.team_id = t.id '
            . 'JOIN schedule sc ON sc.id = s.schedule_id '
            . 'JOIN schedule_plan p ON p.id = sc.schedule_plan_id AND p.type = \'SEASON\' '
            . 'WHERE c.calendar_entry_id IS NULL AND c.scope = \'TEAM\' AND c.rule_type = \'HARD\' '
            . 'AND c.family IN (\'DAY\', \'TIME\')',
        );
        self::assertNotSame([], $rows, 'le seed doit produire des règles dures d\'équipe ET un planning transcrit — sinon ce test ne garde rien');

        $violations = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $config */
            $config = json_decode($row['config'], true, 512, \JSON_THROW_ON_ERROR);
            $day = (int) $row['day'];
            $start = (string) $row['start'];

            $allowed = $config['allowedDays'] ?? null;
            if (\is_array($allowed) && !\in_array($day, array_map('intval', $allowed), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $forbidden = $config['forbiddenDays'] ?? null;
            if (\is_array($forbidden) && \in_array($day, array_map('intval', $forbidden), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $min = $config['minStartTime'] ?? null;
            if (\is_string($min) && $start < $min) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
            $max = $config['maxStartTime'] ?? null;
            if (\is_string($max) && $start > $max) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
        }

        self::assertSame([], array_values(array_unique($violations)), 'une règle dure du seed contredit le planning réel qu\'il transcrit');
    }

    /**
     * P5-13 — chaque plan de REPRISE pointe (chosen) une version COMPLETED transcrivant sa
     * semaine au bon nombre de créneaux (25 pour le 17 août, 38 pour le 24 août), et porte ses
     * groupes de mutualisation ANCRÉS au plan : {SM1,SM2}+{SF1,SF2} le 17 (SF séparées ⇒ absentes
     * le 24), {SM1,SM2} seul le 24.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachRepriseWeekPlanPointsCompletedTranscriptionWithItsGroups(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        foreach ([['Reprise du 17 août', 25, 2], ['Reprise du 24 août', 38, 1]] as [$planName, $expectedSlots, $expectedGroups]) {
            $row = $this->connection->fetchAssociative(
                'SELECT s.status, (SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
                . 'FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
                . 'WHERE sp.club_id = ? AND sp.name = ? AND sp.type = \'HOLIDAY\'',
                [$club->getId(), $planName],
            );
            self::assertNotFalse($row, \sprintf('le plan « %s » pointe une version choisie', $planName));
            self::assertSame('COMPLETED', (string) $row['status'], \sprintf('« %s » pointe une version COMPLETED', $planName));
            self::assertSame($expectedSlots, (int) $row['slot_count'], \sprintf('« %s » transcrit %d séances', $planName, $expectedSlots));

            $groupCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM shared_training_group g JOIN schedule_plan sp ON sp.id = g.schedule_plan_id '
                . 'WHERE sp.club_id = ? AND sp.name = ?',
                [$club->getId(), $planName],
            );
            self::assertSame($expectedGroups, $groupCount, \sprintf('« %s » porte %d groupe(s) de mutualisation', $planName, $expectedGroups));
        }

        // {SF1,SF2} n'est mutualisé QUE la semaine du 17 : aucun groupe de la semaine du 24 ne
        // contient SF1 ni SF2 (elles s'y entraînent séparément).
        $sfGroupsWeek24 = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT gt.group_id) FROM shared_training_group_team gt '
            . 'JOIN schedule_plan sp ON sp.id = gt.schedule_plan_id '
            . 'JOIN team t ON t.id = gt.team_id '
            . 'WHERE sp.club_id = ? AND sp.name = ? AND t.name IN (\'SF1\', \'SF2\')',
            [$club->getId(), 'Reprise du 24 août'],
        );
        self::assertSame(0, $sfGroupsWeek24, 'SF1/SF2 ne sont pas mutualisées la semaine du 24 août');

        // {SM1,SM2} l'est LES DEUX semaines : chaque plan a un groupe couvrant exactement SM1 et SM2.
        foreach (['Reprise du 17 août', 'Reprise du 24 août'] as $planName) {
            $smMembers = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM shared_training_group_team gt '
                . 'JOIN schedule_plan sp ON sp.id = gt.schedule_plan_id '
                . 'JOIN team t ON t.id = gt.team_id '
                . 'WHERE sp.club_id = ? AND sp.name = ? AND t.name IN (\'SM1\', \'SM2\')',
                [$club->getId(), $planName],
            );
            self::assertSame(2, $smMembers, \sprintf('« %s » mutualise SM1 et SM2', $planName));
        }
    }

    /**
     * NR (P5-13) — LES SEMAINES DE REPRISE TRANSCRITES RESPECTENT LES RÈGLES DURES D'ÉQUIPE
     * QU'ELLES N'ONT PAS DÉCOCHÉES.
     *
     * Miroir période de {@see testTheTranscribedRealScheduleSatisfiesEveryHardTeamRule}, avec un
     * amendement essentiel : une contrainte DÉCOCHÉE pour le plan (ConstraintPeriodOverride
     * isActive=false) n'est PAS exigée — c'est précisément le mécanisme qui rend une reprise
     * cohérente avec les règles de saison qu'elle contredit (SM1 hors mardi/jeudi, etc.). Toute
     * règle dure d'équipe (jour/horaire) NON décochée doit, elle, être satisfaite par chaque
     * séance transcrite de la semaine.
     *
     * PORTÉE ASSUMÉE (identique au test de saison) : seules les contraintes de portée ÉQUIPE
     * sont couvertes ; la résolution des règles CLUB par tag vit dans le builder, pas dans le seed.
     *
     * Falsifiable : retirer un décochage du seed (p. ex. « SM1 · uniquement mardi, jeudi » de la
     * semaine du 17) rend ce test ROUGE en nommant la séance du lundi de SM1.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTranscribedReprisePlansSatisfyEveryHardTeamRuleHonouringOverrides(): void
    {
        $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{team: string, day: int, start: string, name: string, family: string, config: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, s.day_of_week AS day, to_char(s.start_time, \'HH24:MI\') AS start, '
            . 'c.name AS name, c.family AS family, c.config::text AS config '
            . 'FROM "constraint" c '
            . 'JOIN team t ON t.id = c.scope_target_id '
            . 'JOIN schedule_slot_template s ON s.team_id = t.id '
            . 'JOIN schedule sc ON sc.id = s.schedule_id '
            . 'JOIN schedule_plan p ON p.id = sc.schedule_plan_id AND p.type = \'HOLIDAY\' AND sc.id = p.chosen_schedule_id '
            . 'WHERE c.calendar_entry_id IS NULL AND c.scope = \'TEAM\' AND c.rule_type = \'HARD\' '
            . 'AND c.family IN (\'DAY\', \'TIME\') '
            // Une règle DÉCOCHÉE pour ce plan (isActive=false) n'est pas exigée.
            . 'AND NOT EXISTS (SELECT 1 FROM constraint_period_override o '
            . 'WHERE o.schedule_plan_id = p.id AND o.constraint_id = c.id AND o.is_active = false)',
        );
        self::assertNotSame([], $rows, 'le seed doit produire des règles dures d\'équipe ET des semaines de reprise transcrites — sinon ce test ne garde rien');

        $violations = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $config */
            $config = json_decode($row['config'], true, 512, \JSON_THROW_ON_ERROR);
            $day = (int) $row['day'];
            $start = (string) $row['start'];

            $allowed = $config['allowedDays'] ?? null;
            if (\is_array($allowed) && !\in_array($day, array_map('intval', $allowed), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $forbidden = $config['forbiddenDays'] ?? null;
            if (\is_array($forbidden) && \in_array($day, array_map('intval', $forbidden), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $min = $config['minStartTime'] ?? null;
            if (\is_string($min) && $start < $min) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
            $max = $config['maxStartTime'] ?? null;
            if (\is_string($max) && $start > $max) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
        }

        self::assertSame([], array_values(array_unique($violations)), 'une règle dure NON décochée du seed contredit une semaine de reprise transcrite');
    }

    /**
     * P5-13 — les reprises et le compte Nicolas ne visent QUE le profil dev. Le club de
     * DÉMONSTRATION ne porte aucun plan de période (HOLIDAY/CLOSURE), aucune entrée calendrier,
     * et le compte gestionnaire Nicolas n'existe pas.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDemoSeedCarriesNoRepriseNorNicolasAccount(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::demo('demo-pass-reprise'));

        $periodPlans = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM schedule_plan WHERE club_id = ? AND type IN (\'HOLIDAY\', \'CLOSURE\')',
            [$club->getId()],
        );
        self::assertSame(0, $periodPlans, 'la démo ne porte aucun plan de période');

        $entries = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_entry WHERE club_id = ?',
            [$club->getId()],
        );
        self::assertSame(0, $entries, 'la démo ne pose aucune entrée calendrier');

        $nicolas = $this->connection->fetchOne(
            'SELECT 1 FROM app_user WHERE email = ?',
            ['nicolas.barilleau@bccl.fr'],
        );
        self::assertFalse($nicolas, 'la démo ne crée pas le compte gestionnaire Nicolas');
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

    /**
     * @return array{clubs:int, teams:int, slots:int, reservations:int, schedules:int, slotTemplates:int, clubUsers:int, calendarEntries:int, schedulePlans:int, sharedGroups:int, sharedGroupTeams:int, venuePeriodOverrides:int, teamPeriodOverrides:int, constraintPeriodOverrides:int}
     */
    private function counts(): array
    {
        return [
            'clubs' => $this->rowsIn('club'),
            'teams' => $this->rowsIn('team'),
            'slots' => $this->rowsIn('venue_training_slot'),
            'reservations' => $this->rowsIn('reservation'),
            // P5-17 : la version transcrite et ses créneaux entrent dans la mesure
            // d'idempotence — un second seed ne doit ni dupliquer la version (find-or-create
            // par plan+provenance) ni doubler ses 90 créneaux (purge l.853 + réinsertion).
            'schedules' => $this->rowsIn('schedule'),
            'slotTemplates' => $this->rowsIn('schedule_slot_template'),
            // P5-13 : le compte gestionnaire additionnel (Nicolas), les deux entrées-semaines
            // + leur mère, les plans de reprise et TOUS leurs réglages ancrés au plan
            // (mutualisation, overrides gymnase/équipe/contrainte) entrent dans la mesure —
            // find-or-create / purge-recréation partout, deux runs = mêmes comptes.
            'clubUsers' => $this->rowsIn('club_user'),
            'calendarEntries' => $this->rowsIn('calendar_entry'),
            'schedulePlans' => $this->rowsIn('schedule_plan'),
            'sharedGroups' => $this->rowsIn('shared_training_group'),
            'sharedGroupTeams' => $this->rowsIn('shared_training_group_team'),
            'venuePeriodOverrides' => $this->rowsIn('venue_period_override'),
            'teamPeriodOverrides' => $this->rowsIn('team_period_override'),
            'constraintPeriodOverrides' => $this->rowsIn('constraint_period_override'),
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
