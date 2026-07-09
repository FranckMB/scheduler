<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Non-regression for the cockpit state machine (accueil-cockpit-temporel.md):
 * a match cannot be created until the season's main plan is validated. A
 * freshly registered club has no baseline / no validated socle → creating a
 * fixture is refused with 409 (SocleGuard), before any other validation.
 *
 * @see \App\Service\SocleGuard
 */
#[Group('phase1')]
#[Group('integration')]
final class SocleGuardTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testCreatingAMatchIsRefusedWhileTheSocleIsNotValidated(): void
    {
        $token = $this->register();

        // A well-formed input (dummy teamId passes DTO validation) so the request
        // reaches the processor — where the socle guard refuses it BEFORE the team
        // existence check (409, not 404).
        $this->client->request('POST', '/api/fixtures', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'teamId' => '00000000-0000-4000-8000-000000000000',
            'matchDate' => '2027-03-06',
            'opponentLabel' => 'Voisins',
            'homeAway' => 'HOME',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'a match requires the main plan validated first');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'socle' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'S', 'lastName' => 'Ocle', 'ara' => strtoupper($suffix), 'club_name' => 'Club Socle',
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        return $token;
    }
}
