<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-20 — GET /api/ffbb/salles (autocomplétion des gymnases du wizard).
 * Le backend FFBB est le stub déterministe (services_test.yaml) : deux salles
 * au CP 69100, dont l'ordre du stub est INVERSÉ par le tri serveur, plus un
 * hit sans libellé que le mapping doit écarter.
 */
#[Group('integration')]
final class FfbbSallesTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private string $adminToken;

    public function testSallesOfExplicitPostalCodeMappedAndSorted(): void
    {
        $data = $this->get('/api/ffbb/salles?postalCode=69100', $this->adminToken);

        self::assertSame('69100', $data['postalCode']);
        // Le hit sans libellé est écarté ; le tri est alphabétique (le stub
        // sert ZOLA avant MATEO — l'inverse prouve le tri serveur).
        self::assertSame(['GYMNASE MATEO', 'SALLE ZOLA'], array_column($data['salles'], 'name'));
        self::assertSame(
            ['name' => 'GYMNASE MATEO', 'address' => '5 BIS RUE EMILE DUNIERE', 'city' => 'Villeurbanne', 'externalRef' => '166926604', 'latitude' => '45.78017', 'longitude' => '4.88467'],
            $data['salles'][0],
            'mapping serveur : champs utiles seulement, lat/lng en string (format Venue)',
        );
    }

    public function testDefaultsToTheClubPostalCode(): void
    {
        $this->club->setPostalCode('69100');
        $this->em->flush();

        $data = $this->get('/api/ffbb/salles', $this->adminToken);
        self::assertSame('69100', $data['postalCode'], 'sans param, le CP du club fait foi');
        self::assertCount(2, $data['salles']);
    }

    public function testNoUsablePostalCodeYieldsEmptyListNotAnError(): void
    {
        // Ni param ni CP club : la saisie libre du wizard doit rester possible
        // sans bruit — 200, liste vide.
        $data = $this->get('/api/ffbb/salles', $this->adminToken);
        self::assertNull($data['postalCode']);
        self::assertSame([], $data['salles']);

        // Un CP au format invalide ne part JAMAIS vers la FFBB (le filtre est
        // interpolé) : même réponse vide.
        $data = $this->get('/api/ffbb/salles?postalCode=' . urlencode('69100\' OR 1'), $this->adminToken);
        self::assertSame([], $data['salles']);
    }

    public function testManagementGate(): void
    {
        $editorToken = $this->makeMember('editor');
        $this->client->request('GET', '/api/ffbb/salles?postalCode=69100', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'SEC-07 : routes FFBB management-only');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Salles ' . $uid)->setSlug('salles-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('salles' . $uid . '@test.com')->setFirstName('S')->setLastName('L');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->em->flush();

        $this->adminToken = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array<string, mixed> */
    private function get(string $url, string $token): array
    {
        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function makeMember(string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);
        $user = (new User)->setEmail('m' . $uid . '@test.com')->setFirstName('M')->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole($role)->setIsActive(true));
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }
}
