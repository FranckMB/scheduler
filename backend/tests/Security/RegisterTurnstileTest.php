<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Double\TurnstileHttpClientStub;
use App\Tests\StartsFreshBrowserSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * P5-3b — NR d'axe « auth & memberships » (§7.1) : Cloudflare Turnstile sur le
 * register, POSÉ SANS jamais rouvrir l'oracle d'énumération A3 que le contrôleur
 * ferme partout ailleurs.
 *
 * Ce que ce garde tient, et pourquoi structurel :
 *  - INERTE PAR DÉFAUT (secret vide) : le register passe SANS token, byte-intact.
 *    C'est le régime de dev/test ; le secret n'est posé qu'en prod. Ce cas prouve
 *    que brancher Turnstile n'a rien cassé du chemin nominal.
 *  - Secret posé + token absent/invalide → 403, aucun compte créé, et — LE point —
 *    corps 403 IDENTIQUE pour un e-mail frais et un e-mail déjà connu : la garde
 *    est insérée AVANT tout lookup, donc elle ne peut pas trahir l'existence d'un
 *    compte (anti-oracle A3, le pendant du 202 identique du register).
 *  - Token valide → 202, et siteverify appelé sur l'URL EN DUR (SSRF-safe : on
 *    assert l'URL enregistrée par le stub, pas seulement le succès).
 *  - Panne TRANSPORT → 202 (fail-open assumé, D3 : ne pas verrouiller l'entonnoir
 *    d'acquisition sur la panne d'un tiers ; les autres couches tiennent).
 *  - Le rate-limit IP du register reste PRIORITAIRE : épuisé, il répond 429 AVANT
 *    même que Turnstile soit consulté — l'ordre du contrôleur est inchangé.
 *
 * Le secret est posé via l'environnement AVANT le boot du kernel : `%env(TURNSTILE_SECRET)%`
 * est résolu au runtime par le conteneur, à chaque (re)boot browser-kit.
 */
#[Group('phase1')]
#[Group('integration')]
final class RegisterTurnstileTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private static int $ipCounter = 0;

    /** (a) Secret vide → Turnstile inerte : le register passe SANS token, compte créé. */
    public function testRegisterPassesWithoutTokenWhenTurnstileDisabled(): void
    {
        $client = self::createClient();
        $this->startFreshBrowserSession($client);

        [$status] = $this->register($client, ['email' => 'inert@turnstile.fr', 'ara' => 'INERT1']);

        self::assertSame(202, $status, 'sans secret, Turnstile est inerte : le register nominal (sans token) doit répondre 202');
        self::assertSame(1, $this->em()->getRepository(User::class)->count(['email' => 'inert@turnstile.fr']), 'le compte doit être créé comme avant');
    }

    /**
     * (b) Secret posé + token absent/invalide → 403, aucun compte, et corps 403
     * IDENTIQUE pour un e-mail frais et un e-mail déjà connu (anti-oracle A3).
     */
    public function testActiveTurnstileRejectsWithAnEnumerationSafe403(): void
    {
        $this->setTurnstileSecret('test-secret');
        $client = self::createClient();

        // Un compte déjà connu, créé via un token VALIDE (verdict success).
        TurnstileHttpClientStub::$verdict = 'success';
        $this->startFreshBrowserSession($client);
        [$seedStatus] = $this->register($client, ['email' => 'known@turnstile.fr', 'ara' => 'KNOWN1', 'turnstileToken' => 'good']);
        self::assertSame(202, $seedStatus);

        // Désormais tout token est REFUSÉ par Cloudflare.
        TurnstileHttpClientStub::$verdict = 'failure';

        // Token ABSENT → 403 (verify('') est fail-closed sans même appeler le stub).
        $this->startFreshBrowserSession($client);
        [$missingStatus] = $this->register($client, ['email' => 'nobody@turnstile.fr', 'ara' => 'NOBODY1']);
        self::assertSame(403, $missingStatus, 'un token absent doit être refusé (403)');

        // Token INVALIDE, e-mail FRAIS → 403 + aucun compte.
        $this->startFreshBrowserSession($client);
        [$freshStatus, $freshBody] = $this->register($client, ['email' => 'fresh@turnstile.fr', 'ara' => 'FRESH1', 'turnstileToken' => 'bad']);
        self::assertSame(403, $freshStatus);
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'fresh@turnstile.fr']), 'un token refusé ne crée aucun compte');

        // Token INVALIDE, e-mail DÉJÀ CONNU → 403 au corps STRICTEMENT identique.
        $this->startFreshBrowserSession($client);
        [$knownStatus, $knownBody] = $this->register($client, ['email' => 'known@turnstile.fr', 'ara' => 'KNOWN1', 'turnstileToken' => 'bad']);
        self::assertSame(403, $knownStatus);
        self::assertSame($freshBody, $knownBody, 'le 403 Turnstile doit être byte-pour-byte identique frais vs connu (aucun oracle A3)');
    }

    /** (c) Token valide → 202, et siteverify appelé sur l'URL EN DUR (SSRF-safe). */
    public function testValidTokenAcceptsAndCallsTheHardcodedSiteverifyUrl(): void
    {
        $this->setTurnstileSecret('test-secret');
        $client = self::createClient();
        TurnstileHttpClientStub::$verdict = 'success';

        $this->startFreshBrowserSession($client);
        [$status] = $this->register($client, ['email' => 'ok@turnstile.fr', 'ara' => 'OKAY1', 'turnstileToken' => 'good']);

        self::assertSame(202, $status);
        self::assertSame(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            TurnstileHttpClientStub::$lastUrl,
            'la vérification doit taper l\'URL Cloudflare EN DUR (jamais dérivée d\'une entrée)',
        );
    }

    /** (d) Panne TRANSPORT → 202 (fail-open assumé, D3). */
    public function testTransportFailureFailsOpen(): void
    {
        $this->setTurnstileSecret('test-secret');
        $client = self::createClient();
        TurnstileHttpClientStub::$verdict = 'transport';

        $this->startFreshBrowserSession($client);
        [$status] = $this->register($client, ['email' => 'failopen@turnstile.fr', 'ara' => 'FAILO1', 'turnstileToken' => 'good']);

        self::assertSame(202, $status, 'une panne transport de Cloudflare ne doit pas bloquer le register (fail-open)');
        self::assertSame(1, $this->em()->getRepository(User::class)->count(['email' => 'failopen@turnstile.fr']), 'le compte doit être créé malgré la panne du tiers');
    }

    /**
     * (f) Un token PATHOLOGIQUE (au-delà des 2048 chars documentés Turnstile) est
     * refusé SANS appeler Cloudflare : un token géant ne peut pas fabriquer une
     * « panne » d'edge pour emprunter le fail-open (revue sécurité du lot).
     */
    public function testOversizedTokenIsRejectedWithoutCallingCloudflare(): void
    {
        $this->setTurnstileSecret('test-secret');
        $client = self::createClient();

        $this->startFreshBrowserSession($client);
        [$status] = $this->register($client, ['email' => 'huge@turnstile.fr', 'ara' => 'HUGE01', 'turnstileToken' => str_repeat('a', 2049)]);

        self::assertSame(403, $status, 'un token au-delà de la longueur documentée est pathologique → refus');
        self::assertNull(TurnstileHttpClientStub::$lastUrl, 'le token pathologique ne doit JAMAIS partir vers siteverify');
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'huge@turnstile.fr']));
    }

    /**
     * (g) Cloudflare JOIGNABLE mais réponse illisible (page HTML d'edge) → fail-CLOSED :
     * ce chemin est atteignable par une entrée forgée, il n'offre pas le fail-open
     * réservé aux vraies pannes transport (revue sécurité du lot).
     */
    public function testUnreadableSiteverifyResponseFailsClosed(): void
    {
        $this->setTurnstileSecret('test-secret');
        $client = self::createClient();
        TurnstileHttpClientStub::$verdict = 'garbage';

        $this->startFreshBrowserSession($client);
        [$status] = $this->register($client, ['email' => 'garbage@turnstile.fr', 'ara' => 'GARB01', 'turnstileToken' => 'good']);

        self::assertSame(403, $status, 'une réponse illisible d\'un Cloudflare joignable doit refuser, pas ouvrir');
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'garbage@turnstile.fr']));
    }

    /** (e) Le rate-limit IP du register reste PRIORITAIRE : épuisé → 429 avant Turnstile. */
    public function testRegisterRateLimitStillFiresBeforeTurnstile(): void
    {
        $this->setTurnstileSecret('test-secret');
        $client = self::createClient();
        // Turnstile refuserait ce token — mais le 429 doit tomber AVANT qu'il soit consulté.
        TurnstileHttpClientStub::$verdict = 'failure';

        $ip = '203.0.113.77';
        $factory = self::getContainer()->get('limiter.auth_register');
        \assert($factory instanceof RateLimiterFactory);
        $limiter = $factory->create($ip);
        $limiter->reset();
        // Draine le budget de cette IP (borne par IP, petite en test) : la boucle
        // s'arrête au premier refus, laissant le limiteur épuisé pour la requête.
        while ($limiter->consume(1)->isAccepted()) {
            // vidange
        }

        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], (string) json_encode([
            'email' => 'throttled@turnstile.fr', 'password' => 'Password123!',
            'firstName' => 'Th', 'lastName' => 'Rottled', 'ara' => 'THROT1', 'club_name' => 'Throttled Club',
            'consent' => true, 'turnstileToken' => 'bad',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(429, $client->getResponse()->getStatusCode(), 'le rate-limit register doit répondre 429 AVANT même de consulter Turnstile');
    }

    protected function setUp(): void
    {
        TurnstileHttpClientStub::reset();
        $this->clearTurnstileSecret();
    }

    protected function tearDown(): void
    {
        $this->clearTurnstileSecret();
        TurnstileHttpClientStub::reset();
        parent::tearDown();
    }

    /**
     * POST /api/register with a valid default payload merged with $overrides.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{0: int, 1: string} [status, raw body]
     */
    private function register(KernelBrowser $client, array $overrides): array
    {
        $payload = array_merge([
            'password' => 'Password123!',
            'firstName' => 'Tur', 'lastName' => 'Nstile',
            'club_name' => 'Turnstile Club', 'consent' => true,
        ], $overrides);

        $client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode($payload, \JSON_THROW_ON_ERROR));

        return [
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        ];
    }

    private function em(): EntityManagerInterface
    {
        // Le conteneur de test suit le kernel courant à travers les reboots browser-kit.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function setTurnstileSecret(string $secret): void
    {
        $_SERVER['TURNSTILE_SECRET'] = $secret;
        $_ENV['TURNSTILE_SECRET'] = $secret;
        putenv('TURNSTILE_SECRET=' . $secret);
    }

    private function clearTurnstileSecret(): void
    {
        unset($_SERVER['TURNSTILE_SECRET'], $_ENV['TURNSTILE_SECRET']);
        putenv('TURNSTILE_SECRET');
    }

    private function nextIp(): string
    {
        $ip = '10.5.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;

        return $ip;
    }
}
