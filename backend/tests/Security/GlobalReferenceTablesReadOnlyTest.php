<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-14: the GLOBAL reference tables (PriorityTier, Plan, Sport) have no club_id and
 * are read by the solver / billing for EVERY tenant — a write through the tenant API
 * would tamper cross-club (solver catalogue) or falsify pricing. Writes must be rejected
 * (the operations are removed → 405). Reads stay open.
 */
#[Group('phase1')]
#[Group('integration')]
final class GlobalReferenceTablesReadOnlyTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @return iterable<string, array{0: string}> */
    public static function globalCollections(): iterable
    {
        yield 'priority-tiers' => ['/api/priority_tiers'];
        yield 'plans' => ['/api/plans'];
        yield 'sports' => ['/api/sports'];
    }

    #[DataProvider('globalCollections')]
    public function testCollectionPostIsRejected(string $collection): void
    {
        $this->client->loginUser($this->seedMember());

        // POST on a resource whose write operations were removed → 405 Method Not Allowed
        // (never a 2xx that would mutate a table read by every other club).
        $this->client->request('POST', $collection, [], [], ['CONTENT_TYPE' => 'application/ld+json'], '{}');
        self::assertSame(405, $this->client->getResponse()->getStatusCode(), "POST {$collection} must be rejected");
    }

    #[DataProvider('globalCollections')]
    public function testItemPutAndDeleteAreRejected(string $collection): void
    {
        $this->client->loginUser($this->seedMember());

        // Item routes only expose GET → PUT/DELETE → 405 (checked at routing, before the
        // item is even loaded — an arbitrary id suffices). Guards against a refactor
        // re-adding `new Put`/`new Delete` and reopening the cross-tenant write surface.
        $item = $collection . '/1';
        $this->client->request('PUT', $item, [], [], ['CONTENT_TYPE' => 'application/ld+json'], '{}');
        self::assertSame(405, $this->client->getResponse()->getStatusCode(), "PUT {$item} must be rejected");

        $this->client->request('DELETE', $item);
        self::assertSame(405, $this->client->getResponse()->getStatusCode(), "DELETE {$item} must be rejected");
    }

    #[DataProvider('globalCollections')]
    public function testReadStaysOpen(string $collection): void
    {
        $this->client->loginUser($this->seedMember());

        $this->client->request('GET', $collection);
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
    }

    private function seedMember(): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $club = new Club;
        $club->setName('Ref ' . $uid);
        $club->setSlug('ref-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('REF' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('ref-' . $uid . '@test.com');
        $user->setFirstName('R');
        $user->setLastName('Ef');
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

        return $user;
    }
}
