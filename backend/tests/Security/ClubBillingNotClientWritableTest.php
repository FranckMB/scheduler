<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-15: plan / billing / quota fields are server-managed — a club admin must NOT be
 * able to self-assign a plan or reset the generation quota via PUT /api/clubs/{id}. The
 * fields are absent from ClubInput, so any such body key is ignored (entity unchanged).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubBillingNotClientWritableTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testPlanAndQuotaFieldsAreIgnoredOnClubPut(): void
    {
        [$user, $club] = $this->seed();
        $originalPlanId = $club->getPlanId();
        $originalBillingCycle = $club->getBillingCycle();
        $originalPlanExpiresAt = $club->getPlanExpiresAt();
        $clubId = $club->getId();

        $this->client->loginUser($user);
        $this->client->request('PUT', "/api/clubs/{$clubId}", [], [], ['CONTENT_TYPE' => 'application/ld+json'], json_encode([
            'name' => 'Renamed Club',
            'slug' => $club->getSlug(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
            // Attacker payload: try to self-assign a plan + billing state + wipe the quota.
            'planId' => 999,
            'billingCycle' => 'annual',
            'planExpiresAt' => '2099-12-31T00:00:00+00:00',
            'generationCountSeason' => 0,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Club::class)->find($clubId);
        self::assertSame('Renamed Club', $reloaded?->getName(), 'the legit field IS applied');
        self::assertSame($originalPlanId, $reloaded?->getPlanId(), 'planId must be ignored');
        self::assertSame($originalBillingCycle, $reloaded?->getBillingCycle(), 'billingCycle must be ignored');
        self::assertEquals($originalPlanExpiresAt, $reloaded?->getPlanExpiresAt(), 'planExpiresAt must be ignored');
        self::assertSame(7, $reloaded?->getGenerationCountSeason(), 'quota counter must be ignored (stays 7)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Club} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $club = new Club;
        $club->setName('Billing ' . $uid);
        $club->setSlug('billing-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('BILL' . strtoupper(substr(md5($uid), 0, 7)));
        $club->setGenerationCountSeason(7);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('bill-' . $uid . '@test.com');
        $user->setFirstName('B');
        $user->setLastName('Ill');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return [$user, $club];
    }
}
