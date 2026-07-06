<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SeedLeagueWindowsCommand;
use App\Entity\LeagueMatchWindow;
use App\Repository\LeagueMatchWindowRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The AURA seed loads the federation match-window catalog idempotently
 * (spec gestion-matchs §6bis).
 */
#[Group('phase1')]
#[Group('integration')]
final class SeedLeagueWindowsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private LeagueMatchWindowRepository $repository;

    public function testSeedsAuraCatalogAndIsIdempotent(): void
    {
        $first = $this->runSeed();
        $first->assertCommandIsSuccessful();
        self::assertStringContainsString('21 created, 0 updated', $first->getDisplay());
        self::assertCount(21, $this->repository->findAll());

        // Spot-check a known AURA window: départemental U13 samedi 13h–18h.
        $u13 = $this->repository->findOneBy(['league' => 'AURA', 'category' => 'U13', 'level' => 'DEPARTEMENTAL', 'dayOfWeek' => 6]);
        self::assertNotNull($u13);
        self::assertSame('13:00', $u13->getKickoffMin()->format('H:i'));
        self::assertSame('18:00', $u13->getKickoffMax()->format('H:i'));

        // A gendered regional row (U18 Région Garçon dimanche).
        $u18mReg = $this->repository->findOneBy(['league' => 'AURA', 'category' => 'U18', 'level' => 'REGIONAL', 'gender' => 'M']);
        self::assertNotNull($u18mReg);

        // Re-run → no new rows.
        $second = $this->runSeed();
        $second->assertCommandIsSuccessful();
        self::assertStringContainsString('0 created, 21 updated', $second->getDisplay());
        self::assertCount(21, $this->repository->findAll());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(LeagueMatchWindowRepository::class);
        // Global reference table (no RLS): clean it for a deterministic count.
        $this->em->createQuery('DELETE FROM ' . LeagueMatchWindow::class . ' w')->execute();
    }

    private function runSeed(): CommandTester
    {
        $command = self::getContainer()->get(SeedLeagueWindowsCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
