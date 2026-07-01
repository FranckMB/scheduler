<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class AuthFlowTest extends WebTestCase
{
    private static int $ipCounter = 0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testRegisterNewAraCreatesActiveAdmin(): void
    {
        $data = $this->register([
            'email' => 'admin@newclub.fr',
            'password' => 'password123',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
            'ara' => 'NEWARA1',
            'club_name' => 'New Club',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('active', $data['membershipStatus']);
        self::assertNotEmpty($data['token']);
        self::assertNotNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'NEWARA1']));
    }

    public function testRegisterExistingAraCreatesPendingMembership(): void
    {
        // First registration creates the club (active admin).
        $this->register([
            'email' => 'owner@club.fr', 'password' => 'password123',
            'firstName' => 'Owner', 'lastName' => 'One', 'ara' => 'EXIST1', 'club_name' => 'Existing Club',
        ]);

        // Second registration on the same ARA -> pending membership, no new club.
        $data = $this->register([
            'email' => 'joiner@club.fr', 'password' => 'password123',
            'firstName' => 'Joiner', 'lastName' => 'Two', 'ara' => 'EXIST1', 'club_name' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $data['membershipStatus']);

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'EXIST1']);
        self::assertCount(1, $this->em->getRepository(Club::class)->findBy(['ffbbClubCode' => 'EXIST1']), 'no duplicate club');

        $memberships = $this->em->getRepository(ClubUser::class)->findBy(['clubId' => $club->getId()]);
        $pending = array_filter($memberships, static fn (ClubUser $m): bool => !$m->getIsActive());
        self::assertCount(1, $pending, 'joiner is a pending membership');
    }

    public function testRegisterDuplicateEmailIsRejected(): void
    {
        $payload = [
            'email' => 'dup@club.fr', 'password' => 'password123',
            'firstName' => 'Dup', 'lastName' => 'User', 'ara' => 'DUP1', 'club_name' => 'Dup Club',
        ];
        $this->register($payload);
        $payload['ara'] = 'DUP2';
        $this->register($payload);

        self::assertResponseStatusCodeSame(409);
    }

    public function testRegisterRequiresFirstAndLastName(): void
    {
        $this->register([
            'email' => 'noname@club.fr', 'password' => 'password123',
            'ara' => 'NONAME1', 'club_name' => 'No Name Club',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, string> $payload */
    private function register(array $payload): array
    {
        // Unique client IP per call so the per-IP register rate limiter never trips in tests.
        $ip = '10.0.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
