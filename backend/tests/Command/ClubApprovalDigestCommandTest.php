<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ClubCreationRequest;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * P3-4 PR B — la cadence fondateur (2026-08-05) : relances à 3 jours restants
 * et le jour J, expiration passée la date (le lien public meurt, la console
 * superadmin garde la main). Idempotent au jour près (remindedAt).
 */
#[Group('integration')]
final class ClubApprovalDigestCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    public function testRemindsAtNamedDaysAndExpiresOverdue(): void
    {
        $today = new DateTimeImmutable('2026-09-10');
        // J-3 (expire le 13) → relance ; J-5 → rien ; jour J (expire le 10) →
        // relance ; échue (le 9) → expirée ; sans mail club → jamais relancée.
        $atJ3 = $this->request('2026-09-13', 'j3@club.test');
        $quiet = $this->request('2026-09-15', 'j5@club.test');
        $atJ0 = $this->request('2026-09-10', 'j0@club.test');
        $overdue = $this->request('2026-09-09', 'late@club.test');
        $noMail = $this->request('2026-09-13', null);

        $tester = new CommandTester(new Application(self::$kernel)->find('app:club-approvals:digest'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => $today->format('Y-m-d')]));

        $this->em->clear();
        $reload = fn (ClubCreationRequest $r): ?ClubCreationRequest => $this->em->find(ClubCreationRequest::class, $r->getId());
        self::assertSame('2026-09-10', $reload($atJ3)?->getRemindedAt()?->format('Y-m-d'), 'J-3 : relancé');
        self::assertNull($reload($quiet)?->getRemindedAt(), 'J-5 : silence');
        self::assertSame('2026-09-10', $reload($atJ0)?->getRemindedAt()?->format('Y-m-d'), 'jour J : relancé');
        self::assertSame(ClubCreationRequest::STATUS_EXPIRED, $reload($overdue)?->getStatus(), 'échue → expirée (la console garde la main)');
        self::assertNull($reload($noMail)?->getRemindedAt(), 'sans mail FFBB : rien à relancer');
        self::assertSame(ClubCreationRequest::STATUS_PENDING, $reload($noMail)?->getStatus());

        // Idempotence au jour près : un second run du même jour ne re-relance pas
        // (remindedAt inchangé) et ne casse rien.
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => $today->format('Y-m-d')]));
        $this->em->clear();
        self::assertSame('2026-09-10', $reload($atJ3)?->getRemindedAt()?->format('Y-m-d'));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function request(string $expiresAt, ?string $clubEmail): ClubCreationRequest
    {
        $uid = uniqid('', true);
        $user = new User;
        $user->setEmail('digest-' . $uid . '@t.fr');
        $user->setFirstName('Di');
        $user->setLastName('Gest');
        $user->setPasswordHash('x');
        $this->em->persist($user);

        $request = new ClubCreationRequest;
        $request->setUserId($user->getId());
        $request->setAra('DIG' . strtoupper(substr(md5($uid), 0, 7)));
        $request->setClubName('Club Digest');
        $request->setClubEmail($clubEmail);
        $request->setExpiresAt(new DateTimeImmutable($expiresAt));
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }
}
