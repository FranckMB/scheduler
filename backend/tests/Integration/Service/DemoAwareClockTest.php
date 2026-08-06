<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-16 / P2-4 — l'horloge sait MENTIR pour un club démo, et SEULEMENT pour lui.
 *
 * Trois propriétés, chacune son cas :
 *  1. club à `demo_today` posé + requête tenant-résolue → now() rend CETTE date
 *     (l'heure du jour reste réelle : durées et TTL gardent un sens) ;
 *  2. club SANS `demo_today` → horloge réelle (le cas de tous les vrais clubs —
 *     zéro changement de comportement) ;
 *  3. hors requête (workers, crons, commandes) → horloge réelle, même si des
 *     clubs démo existent : le contexte tenant est la SEULE clé.
 *
 * Le service est obtenu par le CONTENEUR (ClockInterface), pas construit à la
 * main : c'est la chaîne réelle de décoration qui est testée — en env test,
 * SimulatedClock (non épinglé) délègue à `clock`, que DemoAwareClock décore.
 */
#[Group('integration')]
final class DemoAwareClockTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ClockInterface $clock;

    private RequestStack $requestStack;

    public function testDemoClubLivesOnItsSimulatedDate(): void
    {
        $clubId = $this->club(demoToday: new DateTimeImmutable('2026-12-15'));
        $this->pushTenantRequest($clubId);

        $now = $this->clock->now();

        self::assertSame('2026-12-15', $now->format('Y-m-d'), 'la DATE est celle du club démo');
        // L'HEURE reste réelle : à minuit près (le test peut franchir une seconde),
        // l'écart avec l'horloge machine doit être négligeable.
        self::assertLessThan(5, abs($now->getTimestamp() - new DateTimeImmutable('2026-12-15 ' . new DateTimeImmutable()->format('H:i:s'))->getTimestamp()), 'seule la date est simulée, pas l\'heure du jour');
    }

    public function testRealClubStaysOnTheRealClock(): void
    {
        $clubId = $this->club(demoToday: null);
        $this->pushTenantRequest($clubId);

        self::assertSame(new DateTimeImmutable()->format('Y-m-d'), $this->clock->now()->format('Y-m-d'), 'un vrai club (demo_today NULL) vit à la date réelle');
    }

    public function testOutsideARequestTheClockIsReal(): void
    {
        // Un club démo EXISTE, mais aucun contexte de requête : workers, crons et
        // commandes doivent vivre à l'heure réelle — le contexte tenant est la clé.
        $this->club(demoToday: new DateTimeImmutable('2026-12-15'));

        self::assertSame(new DateTimeImmutable()->format('Y-m-d'), $this->clock->now()->format('Y-m-d'));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
        $this->requestStack = self::getContainer()->get(RequestStack::class);
    }

    protected function tearDown(): void
    {
        // Ne jamais laisser une requête poussée fuir vers le test suivant.
        while ($this->requestStack->getCurrentRequest() instanceof Request) {
            $this->requestStack->pop();
        }
        parent::tearDown();
    }

    private function club(?DateTimeImmutable $demoToday): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Demo ' . $suffix)->setSlug('demo-clock-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setDemoToday($demoToday);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club->getId();
    }

    private function pushTenantRequest(string $clubId): void
    {
        $request = new Request;
        // Le MÊME attribut que TenantFilterListener pose après le firewall — la
        // décoration ne lit que lui, jamais un header (spoofable).
        $request->attributes->set('_club_id', $clubId);
        $this->requestStack->push($request);
    }
}
