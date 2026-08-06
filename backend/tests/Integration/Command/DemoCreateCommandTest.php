<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * P2-4 — la création d'un club de DÉMONSTRATION prospect, en rendez-vous.
 *
 * Les propriétés qui font la sûreté du geste :
 *  1. le club naît is_demo + non onboardé (le wizard guidé EST la démo), avec
 *     saison + plan + adhésion de l'animateur — prêt au login immédiat ;
 *  2. l'animateur est DÉPLACÉ : une 2e création supprime l'adhésion précédente
 *     (le backend résout le club depuis l'adhésion active du JWT — deux actives
 *     seraient ambiguës) ;
 *  3. un ARA déjà pris est REFUSÉ : une démo ne squatte jamais le code d'un
 *     vrai club (le sien !) — c'est la contrainte du register.
 *
 * ⚠ Le populate FFBB n'est PAS asserté : best-effort réseau, il échoue en test
 * (aucun accès sortant) et la commande doit survivre — c'est le cas nominal ici.
 */
#[Group('integration')]
final class DemoCreateCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CommandTester $tester;

    public function testCreatesTheDemoClubReadyToLogIn(): void
    {
        $email = \sprintf('demo-%s@test.local', bin2hex(random_bytes(4)));

        self::assertSame(0, $this->tester->execute([
            '--ffbb' => $this->ara(),
            '--name' => 'Prospect Basket',
            '--animator-email' => $email,
            '--animator-password' => 'demo-password-123',
        ]));

        $this->em->clear();
        $club = $this->em->getRepository(Club::class)->findOneBy(['name' => 'Prospect Basket']);
        self::assertInstanceOf(Club::class, $club);
        self::assertTrue($club->isDemo(), 'le club est flaggé démo — seul ce chemin le pose');
        self::assertFalse($club->getOnboardingCompleted(), 'non onboardé : le parcours guidé du wizard EST la démo');
        $this->scopeGucToClub($club->getId());
        self::assertNotNull($this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]), 'la saison courante existe — login immédiat');

        $animator = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $animator);
        self::assertNotNull($animator->getEmailVerifiedAt(), 'jamais passé par register : l\'email est réputé vérifié, sinon le login refuse');
        $memberships = $this->em->getRepository(ClubUser::class)->findBy(['userId' => $animator->getId()]);
        self::assertCount(1, $memberships);
        self::assertSame($club->getId(), $memberships[0]->getClubId());
    }

    public function testSecondDemoMovesTheAnimatorInsteadOfAccumulating(): void
    {
        $email = \sprintf('demo-%s@test.local', bin2hex(random_bytes(4)));
        self::assertSame(0, $this->tester->execute(['--ffbb' => $this->ara(), '--name' => 'Prospect A', '--animator-email' => $email, '--animator-password' => 'demo-password-123']));
        self::assertSame(0, $this->tester->execute(['--ffbb' => $this->ara(), '--name' => 'Prospect B', '--animator-email' => $email]));

        $this->em->clear();
        $animator = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $animator);
        $memberships = $this->em->getRepository(ClubUser::class)->findBy(['userId' => $animator->getId()]);
        self::assertCount(1, $memberships, 'l\'animateur est DÉPLACÉ, jamais multi-adhérent');
        $clubB = $this->em->getRepository(Club::class)->findOneBy(['name' => 'Prospect B']);
        self::assertInstanceOf(Club::class, $clubB);
        self::assertSame($clubB->getId(), $memberships[0]->getClubId(), 'son adhésion pointe le DERNIER club démo');
    }

    public function testTakenAraIsRefused(): void
    {
        $ara = $this->ara();
        $suffix = bin2hex(random_bytes(4));
        $real = (new Club)->setName('Vrai Club ' . $suffix)->setSlug('vrai-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $real->setFfbbClubCode($ara);
        $this->em->persist($real);
        $this->em->flush();
        $this->scopeGucToClub($real->getId());

        self::assertSame(1, $this->tester->execute(['--ffbb' => $ara, '--name' => 'Squat', '--animator-email' => 'squat@test.local', '--animator-password' => 'demo-password-123']));
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
    }

    public function testFirstUseWithoutPasswordIsRefused(): void
    {
        self::assertSame(1, $this->tester->execute(['--ffbb' => $this->ara(), '--name' => 'Sans MDP', '--animator-email' => \sprintf('absent-%s@test.local', bin2hex(random_bytes(4)))]));
        self::assertStringContainsString('--animator-password', $this->tester->getDisplay());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tester = new CommandTester(new Application(self::$kernel)->find('app:demo:create'));
    }

    /** Un code FFBB syntaxiquement valide et unique par test (3 lettres + 7 chiffres). */
    private function ara(): string
    {
        return 'ZZZ' . str_pad((string) random_int(0, 9_999_999), 7, '0', \STR_PAD_LEFT);
    }
}
