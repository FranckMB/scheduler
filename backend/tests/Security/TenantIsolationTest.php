<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class TenantIsolationTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testUserCannotAccessOtherClubData(): void
    {
        [$clubA, $clubB, $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubB->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCanAccessOwnClubData(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubA->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testInactiveMembershipBlocksAccess(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();

        $membership = $this->em->getRepository(ClubUser::class)->findOneBy([
            'userId' => $userA->getId(),
            'clubId' => $clubA->getId(),
        ]);
        $membership->setIsActive(false);
        $this->em->flush();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubA->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNoClubHeaderReturnsData(): void
    {
        [, , $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams');
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Club, 2: User} */
    private function createTwoClubs(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $clubA = new Club;
        $clubA->setName('Club A');
        $clubA->setSlug('club-a-' . $uid);
        $clubA->setTimezone('Europe/Paris');
        $clubA->setLocale('fr');
        $clubA->setOnboardingCompleted(true);
        $clubA->setFfbbClubCode('AAA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($clubA);

        $clubB = new Club;
        $clubB->setName('Club B');
        $clubB->setSlug('club-b-' . $uid);
        $clubB->setTimezone('Europe/Paris');
        $clubB->setLocale('fr');
        $clubB->setOnboardingCompleted(true);
        $clubB->setFfbbClubCode('BBB' . strtoupper(substr(md5($uid . 'b'), 0, 10)));
        $this->em->persist($clubB);

        $userA = new User;
        $userA->setEmail('a' . $uid . '@test.com');
        $userA->setFirstName('A');
        $userA->setLastName('User');
        $userA->setPasswordHash($hasher->hashPassword($userA, 'pass'));
        $this->em->persist($userA);

        $this->em->flush();

        $cu = new ClubUser;
        $cu->setClubId($clubA->getId());
        $cu->setUserId($userA->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();

        return [$clubA, $clubB, $userA];
    }
}
