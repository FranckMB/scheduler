<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Double\RecordingPasswordHasher;
use App\Tests\StartsFreshBrowserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * NR d'axe « auth & memberships » (§7.1) — la PARITÉ ANTI-ÉNUMÉRATION du rail
 * mot de passe, le pendant de l'A3 du register (AuthFlowTest) pour /password/*.
 *
 * Ce qu'il garde, et pourquoi structurel plutôt qu'horloge :
 *  - Le forgot répond BYTE-pour-byte identique qu'un compte existe ou non — la
 *    seule garde vérifiable sans mesurer un temps (un test d'horloge serait
 *    instable en CI). Le timing, lui, est ADRESSÉ par le hash factice :
 *  - Sur la branche « compte inexistant », le contrôleur dépense quand même un
 *    hashPassword (compté ici via RecordingPasswordHasher) pour approcher le coût
 *    CPU de la branche existante. On épingle le GESTE, pas une durée.
 *  - Le mail de reset part par le BUS (SendEmailMessage enfilé, pas envoyé dans
 *    la requête) : sans ça un 500 SMTP sur la seule branche « compte connu »
 *    serait un oracle par code d'erreur. On le prouve par l'envelope déposée sur
 *    le transport, ET structurellement en lisant le routing PROD (le re-routage
 *    de test masque le prod à l'exécution — cf. BlockingTestsListMatchesCiTest,
 *    même raison de lire le YAML plutôt que le comportement).
 *  - Le reset a désormais sa borne (429), comme le forgot : le seul endpoint du
 *    rail sans throttle était une porte à brute-forcer un token.
 */
#[Group('phase1')]
#[Group('integration')]
final class PasswordResetEnumerationTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private const string ROOT = __DIR__ . '/../..';

    private static int $ipCounter = 0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testForgotIsByteIdenticalForKnownAndUnknownEmail(): void
    {
        $this->registerVerifiedUser('known@enum.fr', 'ENUMR1');

        [$knownStatus, $knownBody] = $this->postForgot('known@enum.fr');
        [$unknownStatus, $unknownBody] = $this->postForgot('nobody@enum.fr');

        self::assertSame(200, $knownStatus);
        self::assertSame($knownStatus, $unknownStatus, 'le status ne doit pas trahir l\'existence du compte');
        self::assertSame($knownBody, $unknownBody, 'le corps doit être byte-pour-byte identique (aucun oracle)');
    }

    public function testForgotForUnknownEmailStillSpendsAHash(): void
    {
        RecordingPasswordHasher::reset();

        $this->postForgot('ghost@enum.fr');

        self::assertSame(
            1,
            RecordingPasswordHasher::$hashCount,
            'la branche « compte inexistant » doit dépenser un hash factice (équilibrage temporel)',
        );
    }

    public function testForgotForKnownEmailQueuesExactlyOneEmailOnTheBus(): void
    {
        $this->registerVerifiedUser('queued@enum.fr', 'ENUMR2');

        $this->postForgot('queued@enum.fr');

        // Le transport mémoire est remis à zéro à chaque frontière de requête (reboot
        // kernel), donc il ne reflète QUE le forgot ci-dessus : le mail n'est pas parti
        // dans la requête, il a été enfilé.
        $queued = $this->queuedEmails();
        self::assertCount(1, $queued, 'le forgot d\'un compte connu enfile exactement un mail sur le bus');
    }

    public function testProdRoutesTheMailerMessageAsync(): void
    {
        $config = Yaml::parseFile(self::ROOT . '/config/packages/messenger.yaml');
        self::assertIsArray($config);

        $routing = $config['framework']['messenger']['routing'] ?? null;
        self::assertIsArray($routing, 'Le routing messenger a disparu de messenger.yaml.');

        self::assertSame(
            'async',
            $routing['Symfony\Component\Mailer\Messenger\SendEmailMessage'] ?? null,
            'En PROD, les e-mails DOIVENT partir par le bus (async) — sinon un 500 SMTP redevient un oracle d\'énumération.',
        );
    }

    public function testResetIsRateLimited(): void
    {
        // IP dédiée + reset du limiteur avant la série : l'état vit dans le cache
        // (pollution possible entre runs sur une IP fixe). On veut CE 429, précisément.
        $ip = '203.0.113.55';
        $this->resetLimiterFor($ip);

        $statuses = [];
        for ($i = 0; $i < 11; ++$i) {
            $this->requestReset($ip);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertNotSame(429, $statuses[0], 'la première tentative reste dans le budget');
        self::assertSame(429, $statuses[10], 'la 11ᵉ tentative dépasse la borne 10/15min et doit être throttlée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        RecordingPasswordHasher::reset();
    }

    /** Register a user and mark it verified (the reset flow's final login gate expects it). */
    private function registerVerifiedUser(string $email, string $ara): void
    {
        // SEC-16 : le cookie d'auth de l'identité précédente ne doit pas partir avec
        // cette inscription (sinon 429 du quota par utilisateur).
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'En', 'lastName' => 'Um', 'ara' => $ara, 'club_name' => "Club {$ara}", 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        $user?->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->flush();
    }

    /** @return array{0: int, 1: string} [status, raw body] */
    private function postForgot(string $email): array
    {
        $this->client->request('POST', '/api/password/forgot', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], json_encode(['email' => $email], \JSON_THROW_ON_ERROR));

        return [
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        ];
    }

    private function requestReset(string $ip): void
    {
        $this->client->request('POST', '/api/password/reset', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['token' => 'not-a-real-token', 'password' => 'Whatever123!'], \JSON_THROW_ON_ERROR));
    }

    /** @return list<object> the SendEmailMessage instances queued on the in-memory transport */
    private function queuedEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent());
    }

    private function resetLimiterFor(string $ip): void
    {
        $factory = self::getContainer()->get('limiter.auth_password_reset');
        \assert($factory instanceof RateLimiterFactory);
        $factory->create($ip)->reset();
    }

    private function nextIp(): string
    {
        $ip = '10.4.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;

        return $ip;
    }
}
