<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminMonitoringService;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A1 — le badge de la console SA dit l'offre EFFECTIVE, pas l'offre STOCKÉE. La règle
 * pivot a UNE seule maison ({@see PlanEntitlements::effectivePlanCode}) ; ce
 * test garde que le mapping DBAL d'AdminMonitoringService la relaie sans la re-dériver en
 * SQL : si le service et la règle divergent (p. ex. la comparaison code/effectif inversée),
 * les assertions ci-dessous rougissent.
 *
 * AdminMonitoringService lit par la connexion `admin` (superuser, HORS dama). On sème donc
 * dans une transaction ouverte SUR CETTE connexion partagée — le service voit nos lignes non
 * committées (même session) — et on la `rollBack` au teardown : aucune fuite committée.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminMonitoringClubsPlanTest extends KernelTestCase
{
    private Connection $admin;

    private bool $inTransaction = false;

    /** Préfixe unique : filtre les clubs semés hors du parc de fixtures. */
    private string $tag;

    public function testEffectivePlanMirrorsThePivotRuleNotTheStoredOffer(): void
    {
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $beta = $this->planId('beta');

        // Bêta POSÉE mais saison NON réglée (paidSeasonYear null) → effective Découverte.
        $this->seedClub('unpaid', $beta, null, $year);
        // Bêta POSÉE et saison réglée (paidSeasonYear = année-pivot) → effective Bêta.
        $this->seedClub('paid', $beta, $year, $year);
        // Aucune offre stockée → Découverte, plan null.
        $this->seedClub('noplan', null, null, $year);

        $items = self::getContainer()->get(AdminMonitoringService::class)->clubs(1, 100, $this->tag)['items'];
        $byName = [];
        foreach ($items as $item) {
            $byName[(string) $item['name']] = $item;
        }

        self::assertCount(3, $byName, 'les trois clubs semés doivent remonter');

        $unpaid = $byName[$this->tag . ' unpaid'];
        self::assertSame('beta', $unpaid['plan']['code'], 'l\'offre STOCKÉE reste Bêta');
        self::assertSame('decouverte', $unpaid['effectivePlan']['code'], 'l\'offre EFFECTIVE retombe sur Découverte (saison non réglée)');
        self::assertNull($unpaid['paidSeasonYear']);

        $paid = $byName[$this->tag . ' paid'];
        self::assertSame('beta', $paid['plan']['code']);
        self::assertSame('beta', $paid['effectivePlan']['code'], 'saison réglée → l\'effective est bien Bêta');
        self::assertSame($year, $paid['paidSeasonYear']);

        $noplan = $byName[$this->tag . ' noplan'];
        self::assertNull($noplan['plan'], 'aucune offre stockée → plan null');
        self::assertSame('decouverte', $noplan['effectivePlan']['code']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $connection = $registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $this->admin = $connection;
        $this->admin->beginTransaction();
        $this->inTransaction = true;
        $this->tag = 'A1PLAN' . strtoupper(bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    private function planId(string $code): string
    {
        $id = $this->admin->fetchOne('SELECT id FROM subscription_plan WHERE code = :code', ['code' => $code]);
        \assert(\is_string($id), \sprintf('le plan « %s » doit exister dans les fixtures de test', $code));

        return $id;
    }

    private function seedClub(string $suffix, ?string $planId, ?int $paidSeasonYear, int $year): void
    {
        $clubId = $this->admin->fetchOne('SELECT gen_random_uuid()');
        \assert(\is_string($clubId));
        $name = $this->tag . ' ' . $suffix;

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO club (id, created_at, updated_at, name, slug, plan_id, paid_season_year, generation_count_season, timezone, locale, onboarding_completed)
            VALUES (:id, NOW(), NOW(), :name, :slug, :plan_id, :paid, 0, 'Europe/Paris', 'fr', TRUE)
            SQL, [
            'id' => $clubId,
            'name' => $name,
            'slug' => strtolower($this->tag . '-' . $suffix),
            'plan_id' => $planId,
            'paid' => $paidSeasonYear,
        ]);

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data)
            VALUES (gen_random_uuid(), NOW(), NOW(), :club_id, :name, :start, :end, 'ACTIVE', '[]')
            SQL, [
            'club_id' => $clubId,
            'name' => (string) $year,
            'start' => $year . '-08-01',
            'end' => ($year + 1) . '-07-15',
        ]);
    }
}
