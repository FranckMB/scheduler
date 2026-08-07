<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * SEC-16 (audit) — LE CONTRAT DU COOKIE qui porte le JWT applicatif.
 *
 * Le jeton vivait en clair dans `localStorage` : une XSS, une extension véreuse
 * ou une console ouverte sur un poste partagé le lisait et le REJOUAIT AILLEURS
 * pendant toute sa durée de vie. Le correctif ne tient que si quatre propriétés
 * sont vraies ENSEMBLE — chacune seule ne protège rien :
 *
 * 1. le jeton QUITTE le corps de la réponse (sinon le front peut le re-stocker) ;
 * 2. le cookie est `httpOnly` (sinon le JS le lit quand même) ;
 * 3. il est `SameSite=Strict` (c'est TOUTE la défense CSRF : une requête venue
 *    d'un autre site ne le porte pas — le cookie est de l'autorité ambiante) ;
 * 4. le cookie SEUL authentifie (sinon le front ne peut pas s'en passer, et le
 *    jeton retournerait en localStorage).
 *
 * Axe *auth & memberships* (§7.1) : ce test est bloquant (phase1).
 */
#[Group('phase1')]
#[Group('integration')]
final class JwtCookieContractTest extends WebTestCase
{
    private const string PASSWORD = 'Str0ng!Passw0rd';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testLoginPutsTheTokenInAHardenedCookieAndNotInTheBody(): void
    {
        $email = $this->createUser();

        $this->login($email);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('eyJ', $body, 'le corps ne doit plus porter le JWT — sinon le front peut le re-stocker');

        $cookie = $this->jwtCookie();
        self::assertTrue($cookie->isHttpOnly(), 'httpOnly : le JS ne doit pas pouvoir lire le jeton');
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite(), 'SameSite=Strict EST la défense CSRF de ce cookie');
        self::assertSame('/api', $cookie->getPath(), 'le navigateur ne l’envoie qu’à l’API');
        self::assertNotSame('', (string) $cookie->getValue());
    }

    public function testTheCookieAloneAuthenticatesWithoutAnyAuthorizationHeader(): void
    {
        $email = $this->createUser();
        $this->login($email);

        // Le navigateur rejoue le cookie tout seul ; AUCUN en-tête Authorization.
        $this->client->request('GET', '/api/me');

        self::assertResponseIsSuccessful('sans cette propriété, le front devrait garder un jeton lisible');
        self::assertStringContainsString($email, (string) $this->client->getResponse()->getContent());
    }

    public function testTheAuthorizationHeaderStillWorksForNonBrowserCallers(): void
    {
        // Smokes, scripts d'ops et helpers e2e n'ont pas de bocal à cookies : le
        // Bearer reste un chemin d'authentification légitime, pas un reliquat.
        $email = $this->createUser();
        $this->login($email);
        $token = (string) $this->jwtCookie()->getValue();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
    }

    public function testLogoutClearsTheCookieServerSide(): void
    {
        $email = $this->createUser();
        $this->login($email);

        $this->client->request('POST', '/api/logout');

        self::assertResponseIsSuccessful();
        // Le cookie httpOnly n'est effaçable QUE par le serveur : sans cette
        // route, « Se déconnecter » laisserait la session vivre jusqu'à son terme.
        $cleared = $this->jwtCookie();
        self::assertSame('', (string) $cleared->getValue());
        self::assertTrue($cleared->getExpiresTime() < time(), 'le cookie doit être daté dans le passé');

        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401, 'après déconnexion, la session ne doit plus authentifier');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function login(string $email): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => $email,
            'password' => self::PASSWORD,
        ], \JSON_THROW_ON_ERROR));
    }

    private function jwtCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('BEARER' === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('la réponse doit poser le cookie BEARER');
    }

    /** @return string l'email d'un utilisateur actif, sans club (le sujet est l'authentification) */
    private function createUser(): string
    {
        $email = 'jwtcookie' . uniqid('', true) . '@test.com';
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Jwt');
        $user->setLastName('Cookie');
        $user->setPasswordHash(
            self::getContainer()->get('security.user_password_hasher')->hashPassword($user, self::PASSWORD),
        );
        // `UserChecker` refuse un compte non vérifié avec le MÊME message qu'un
        // mauvais mot de passe (A3) — sans cette ligne, le login rend 401.
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();

        return $email;
    }
}
