<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Season;
use App\Entity\Venue;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BCK-05: the custom State providers returned a bare array, so hydra:totalItems
 * reflected only the page size (30), never the real row count — a client could
 * not know how many pages exist. The base now returns a paginator; this locks
 * the real total in.
 */
#[Group('phase1')]
#[Group('integration')]
final class CollectionPaginationTest extends WebTestCase
{
    private KernelBrowser $client;

    public function testCollectionExposesRealTotalItemsNotPageSize(): void
    {
        [$token, $clubId] = $this->register();
        $this->seedVenues($clubId, 31);

        $data = $this->get('/api/venues', $token);

        $total = $data['totalItems'] ?? $data['hydra:totalItems'] ?? null;
        self::assertSame(31, $total, 'totalItems must reflect the real count, not the 30-item page size');

        $member = $data['member'] ?? $data['hydra:member'] ?? [];
        self::assertCount(30, $member, 'the page itself is still capped at the 30-item page size');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function seedVenues(string $clubId, int $count): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $container->get(TenantConnectionContext::class)->setClubId($clubId);

        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season, 'register must have created a season');

        for ($i = 0; $i < $count; ++$i) {
            $venue = new Venue;
            $venue->setClubId($clubId);
            $venue->setSeasonId($season->getId());
            $venue->setName(\sprintf('Gymnase %02d', $i));
            $venue->setSource('manual');
            $em->persist($venue);
        }
        $em->flush();
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function register(): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'pag' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'password123',
            'firstName' => 'P', 'lastName' => 'Ag', 'ara' => strtoupper($suffix), 'club_name' => 'Pag Club',
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $me = $this->get('/api/me', $token);

        return [$token, $me['club']['id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
