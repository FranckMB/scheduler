<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class TenantIsolationTest extends WebTestCase
{
    use TenantGucTrait;

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

    /**
     * Le club porté par le header doit être un UUID CANONIQUE — revue sécurité
     * FRT-04, exploit mesuré sur la stack de dev.
     *
     * PostgreSQL accepte `{710a290720ce40ffa67fc2674ca0dbe7}` comme le même uuid
     * que `710a2907-20ce-...` : un membre pouvait donc envoyer SON PROPRE club
     * sous cette forme dégradée et passer le contrôle d'adhésion (la comparaison
     * est faite par la base, qui normalise). La chaîne, elle, continuait son
     * chemin telle quelle dans `_club_id` — et tout aval qui ne normalise pas
     * héritait d'une valeur choisie par le client. Sur `/api/mercure/auth` elle
     * produisait le sélecteur `club:{710a29...}:schedule:{id}`, soit DEUX
     * variables URI-template : le hub y délivrait les événements de génération
     * de N'IMPORTE QUEL club (reçus en vivant avant correctif).
     *
     * Le club est la frontière tenant : sa FORME se valide au listener, comme
     * celle de la saison, et un écart se refuse — jamais une normalisation
     * silencieuse, qui rendrait le club sous deux graphies « le même » ici et
     * « deux valeurs » ailleurs.
     */
    public function testANonCanonicalClubHeaderIsRejectedEvenForOwnClub(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();
        $mangled = '{' . str_replace('-', '', $clubA->getId()) . '}';

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $mangled,
        ]);

        self::assertResponseStatusCodeSame(403, 'un club sous forme non canonique doit être refusé, même si c’est le sien');
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

        $this->scopeGucToClub($clubA->getId());

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
