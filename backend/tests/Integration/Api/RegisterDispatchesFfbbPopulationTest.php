<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Message\PopulateClubFromFfbbMessage;
use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Lot C non-regression (§7.1 auth & memberships): verifying a registration that
 * CREATES a new club must dispatch PopulateClubFromFfbbMessage (async FFBB
 * autofill); joining an EXISTING club must NOT (it is already populated / will
 * be by its own creator). Guards the register→FFBB wiring.
 */
#[Group('phase1')]
final class RegisterDispatchesFfbbPopulationTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testNewClubDispatchesPopulation(): void
    {
        $this->registerAndVerify('ARA0069777', 'Club Neuf');

        $messages = $this->dispatched();
        self::assertCount(1, $messages, 'a newly created club must enqueue FFBB population');
        self::assertInstanceOf(PopulateClubFromFfbbMessage::class, $messages[0]);
    }

    public function testJoiningExistingClubDoesNotDispatch(): void
    {
        // First registrant creates the club; the in-memory transport is reset at
        // each request boundary, so after the SECOND registrant's join-verify the
        // transport reflects only that request — which must have dispatched nothing.
        $this->registerAndVerify('ARA0069778', 'Club Partagé');
        $this->registerAndVerify('ARA0069778', null);

        self::assertCount(0, $this->dispatched(), 'joining an existing club must not enqueue FFBB population');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep one kernel (and one in-memory transport) across register+verify
        // requests so dispatched messages accumulate instead of resetting.
        $this->client->disableReboot();
    }

    /** @return list<object> the messages sent to the in-memory FFBB transport */
    private function dispatched(): array
    {
        $transport = self::getContainer()->get('messenger.transport.ffbb_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
    }

    private function registerAndVerify(string $ara, ?string $clubName): void
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $payload = ['email' => $suffix . '@test.fr', 'password' => 'Password123!', 'firstName' => 'M', 'lastName' => 'R', 'ara' => $ara];
        if (null !== $clubName) {
            $payload['club_name'] = $clubName;
        }

        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);
    }
}
