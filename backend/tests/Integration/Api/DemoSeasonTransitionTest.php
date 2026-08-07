<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-4 (fondateur 2026-08-07) — « pour un compte démo j'ai un abonnement
 * illimité donc je peux changer de saison » : le gate P1-5 (bascule réservée
 * aux saisons payées) est EXEMPTÉ pour un club de démonstration.
 *
 * MÊME club dans les deux cas — seule la variable testée change (le flag) :
 * sans lui le 409 tombe (le gate P1-5 tient, témoin), avec lui la bascule
 * passe sans aucun paiement.
 */
#[Group('integration')]
final class DemoSeasonTransitionTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testDemoClubTransitionsWithoutPaymentAndRealClubStillCannot(): void
    {
        $token = $this->register();
        $me = $this->request('GET', '/api/me', $token);
        $seasonId = $me['seasons'][0]['id'];
        $clubId = $me['club']['id'];

        // TÉMOIN — club réel, rien payé : le gate P1-5 refuse. Sans ce cas, le
        // vert du suivant pourrait venir d'un gate globalement mort.
        $this->request('POST', '/api/seasons/' . $seasonId . '/transition', $token);
        self::assertResponseStatusCodeSame(409);

        // Le flag démo — posé en SQL : aucun parcours utilisateur ne peut le faire,
        // c'est la commande support qui le pose en prod (app:demo:create).
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setIsDemo(true);
        $em->flush();

        $this->request('POST', '/api/seasons/' . $seasonId . '/transition', $token);
        self::assertResponseStatusCodeSame(201, 'abonnement illimité : un club démo bascule sans paiement');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** Même idiome que SeasonClockThreadingTest : register + verify → JWT. */
    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'dmo' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'D', 'lastName' => 'Demo', 'ara' => strtoupper($suffix), 'club_name' => 'Club Demo Transition', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        return $token;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $uri, string $token): array
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
