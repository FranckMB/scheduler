<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * FRT-04 — le jeton de souscription Mercure (`GET /api/mercure/auth`).
 *
 * Le SCOPE de ce jeton est une frontière tenant : signé du secret du hub, il
 * autorise `subscribe` sur `club:{clubId}:schedule:{id}` — le club du MEMBRE
 * AUTHENTIFIÉ, jamais un paramètre client. Un jeton mal borné ouvrirait les
 * événements de génération d'un autre club (statuts, scores, warnings) à
 * n'importe quel compte : c'est l'axe tenant, donc phase1.
 */
#[Group('phase1')]
#[Group('integration')]
final class MercureAuthTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAnonymousIsRejected(): void
    {
        $this->client->request('GET', '/api/mercure/auth');

        self::assertSame(401, $this->client->getResponse()->getStatusCode(), 'sans JWT applicatif, aucun jeton hub');
    }

    public function testTheCookieIsScopedToTheAuthenticatedUsersClubOnly(): void
    {
        [$tokenA, $clubA] = $this->memberOfAFreshClub('a');
        [$tokenB, $clubB] = $this->memberOfAFreshClub('b');

        $cookieA = $this->fetchAuthCookie($tokenA);
        $cookieB = $this->fetchAuthCookie($tokenB);

        // Le claim `subscribe` est EXACTEMENT le template du club du porteur — pas
        // de wildcard global, pas d'autre topic, pas le club du voisin.
        self::assertSame([\sprintf('club:%s:schedule:{id}', $clubA)], $this->subscribeClaim($cookieA->getValue()));
        self::assertSame([\sprintf('club:%s:schedule:{id}', $clubB)], $this->subscribeClaim($cookieB->getValue()));
        self::assertNotSame($clubA, $clubB);
    }

    public function testTheTokenIsSignedWithTheHubSecretAndDeliveredHttpOnlyToTheHubPath(): void
    {
        [$token, $clubId] = $this->memberOfAFreshClub('sig');

        $cookie = $this->fetchAuthCookie($token);

        // Signature vérifiée avec le secret du HUB : c'est elle qui fait autorité
        // côté Mercure — un jeton signé d'autre chose serait simplement ignoré.
        [$header, $payload, $signature] = explode('.', $cookie->getValue());
        $secret = (string) ($_ENV['MERCURE_JWT_SECRET'] ?? $_SERVER['MERCURE_JWT_SECRET'] ?? '');
        self::assertNotSame('', $secret, 'MERCURE_JWT_SECRET doit exister dans l’environnement de test');
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, $secret, true)), '+/', '-_'), '=');
        self::assertSame($expected, $signature, 'le jeton doit être signé HS256 du MÊME secret que le publieur');

        // httpOnly + path borné : jamais lisible par le JS (le localStorage est déjà
        // le point faible du JWT applicatif — on n'ajoute pas un second jeton exposé),
        // et le navigateur ne l'envoie qu'au hub, pas à l'API.
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());

        // La réponse expose le template comme TOPIC d'abonnement : le front ne
        // connaît pas son clubId (tenant résolu serveur), c'est sa seule source.
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(\sprintf('club:%s:schedule:{id}', $clubId), $body['topicTemplate'] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function fetchAuthCookie(string $token): Cookie
    {
        $this->client->request('GET', '/api/mercure/auth', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('la réponse doit poser le cookie mercureAuthorization');
    }

    /** @return list<string> le claim `mercure.subscribe` du JWT hub */
    private function subscribeClaim(string $jwt): array
    {
        [, $payload] = explode('.', $jwt);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsArray($claims['mercure'] ?? null, 'le JWT hub doit porter le claim mercure');

        return $claims['mercure']['subscribe'] ?? [];
    }

    /** @return array{string, string} [JWT applicatif, clubId] d'un gestionnaire actif d'un club neuf */
    private function memberOfAFreshClub(string $tag): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club Mercure ' . $tag);
        $club->setSlug('club-mercure-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('MRC' . strtoupper(substr(md5($tag . $uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('mercure-' . $tag . $uid . '@test.com');
        $user->setFirstName('Mercure');
        $user->setLastName(ucfirst($tag));
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [self::getContainer()->get(JWTTokenManagerInterface::class)->create($user), $club->getId()];
    }
}
