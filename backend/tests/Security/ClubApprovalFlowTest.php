<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Service\EmailVerifier;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\ReadsJwtCookie;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR P3-4 (axe auth & memberships — décision fondateur 2026-08-05) : le premier
 * inscrivant d'un ARA inconnu ne matérialise PLUS le club à la vérification
 * d'email. Sa demande attend l'approbation du CLUB (lien au mail institutionnel
 * FFBB) ou du superadmin ; l'approbation provisionne (club + membership admin +
 * saison/plan/catégories/contraintes de base), le refus clôt. Anti-énumération :
 * 404 byte-identique pour token inconnu, malformé ou déjà consommé.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubApprovalFlowTest extends WebTestCase
{
    use ReadsJwtCookie;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testUnknownAraWaitsForApprovalWhichProvisionsTheClub(): void
    {
        // ARA connu du stub FFBB → le mail institutionnel est trouvé et stocké.
        $ara = FfbbHttpClientStub::CLUB_CODE;
        $this->deleteClubByAra($ara);
        [$jwt, $body] = $this->registerAndVerify('approve-' . uniqid() . '@t.fr', $ara, 'Club Approbation');

        self::assertSame('club_pending', $body['membershipStatus'], 'la vérification ne crée plus le club');
        self::assertNotSame('', $jwt, 'le demandeur garde son JWT (il verra l\'état de sa demande)');
        self::assertNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]), 'AUCUN club avant approbation');

        $request = $this->pendingRequestFor($body['user']['id']);
        self::assertSame(FfbbHttpClientStub::CLUB_EMAIL, $request->getClubEmail(), 'le mail institutionnel FFBB est résolu à l\'ouverture');

        // /api/me expose l'état de la demande (l'écran « demande transmise »).
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('pending', $me['clubRequest']['status'] ?? null);

        // La page publique montre la demande au destinataire du mail du club.
        $this->client->request('GET', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip()]);
        self::assertResponseIsSuccessful();

        // Approbation → le club EXISTE, provisionné comme un register d'antan.
        $this->client->request('POST', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseIsSuccessful();

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertInstanceOf(Club::class, $club);
        $membership = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $club->getId(), 'userId' => $body['user']['id']]);
        self::assertInstanceOf(ClubUser::class, $membership);
        self::assertTrue($membership->getIsActive(), 'le demandeur approuvé est gestionnaire actif');
        $this->scopeGucToClub($club->getId());
        $counts = $this->em->getConnection()->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM sport_category WHERE club_id = :id) AS categories, (SELECT COUNT(*) FROM "constraint" WHERE club_id = :id) AS constraints, (SELECT COUNT(*) FROM season WHERE club_id = :id) AS seasons',
            ['id' => $club->getId()],
        );
        self::assertIsArray($counts);
        self::assertGreaterThan(0, (int) $counts['categories'], 'workspace seedé : catégories');
        self::assertSame(5, (int) $counts['constraints'], 'workspace seedé : les 5 contraintes de base (P2-16)');
        self::assertSame(1, (int) $counts['seasons'], 'workspace seedé : la saison de naissance');

        // La décision est UNIQUE : le token consommé redevient un 404 anonyme.
        $this->client->request('POST', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseStatusCodeSame(404);

        $this->deleteClubByAra($ara);
    }

    public function testRefusalClosesWithoutCreatingAnything(): void
    {
        $ara = 'REF' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $body] = $this->registerAndVerify('refuse-' . uniqid() . '@t.fr', $ara, 'Club Refusé');
        $request = $this->pendingRequestFor($body['user']['id']);
        // ARA inconnu du stub FFBB → pas de mail institutionnel → file superadmin.
        self::assertNull($request->getClubEmail());

        $this->client->request('POST', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'refuse']));
        self::assertResponseIsSuccessful();

        self::assertNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]), 'refus → aucun club');
        $this->em->clear();
        self::assertSame(ClubCreationRequest::STATUS_REFUSED, $this->em->getRepository(ClubCreationRequest::class)->find($request->getId())?->getStatus());
    }

    public function testUnknownAndMalformedTokensAreByteIdentical(): void
    {
        $this->client->request('GET', '/api/club-approvals/' . str_repeat('a', 64), [], [], ['REMOTE_ADDR' => $this->ip()]);
        $unknown = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/club-approvals/not-a-token', [], [], ['REMOTE_ADDR' => $this->ip()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), 'inconnu et malformé : 404 BYTE-IDENTIQUE (anti-énumération)');
    }

    public function testConcurrentRequestOnTheSameAraBecomesAPendingMembership(): void
    {
        $ara = 'CCR' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $first] = $this->registerAndVerify('first-' . uniqid() . '@t.fr', $ara, 'Club Course');
        [, $second] = $this->registerAndVerify('second-' . uniqid() . '@t.fr', $ara, 'Club Course');

        $winner = $this->pendingRequestFor($first['user']['id']);
        $this->client->request('POST', '/api/club-approvals/' . $winner->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseIsSuccessful();

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertInstanceOf(Club::class, $club);
        // Le second demandeur : jamais un 2e club — une adhésion pending, que le
        // premier gestionnaire approuvera (flux existant).
        $sibling = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $club->getId(), 'userId' => $second['user']['id']]);
        self::assertInstanceOf(ClubUser::class, $sibling);
        self::assertFalse($sibling->getIsActive());
        $this->em->clear();
        self::assertNotSame(ClubCreationRequest::STATUS_PENDING, $this->em->getRepository(ClubCreationRequest::class)->findOneBy(['userId' => $second['user']['id']])?->getStatus(), 'la demande concurrente est close');

        $this->deleteClubByAra($ara);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Register + verify SANS le relais dev — ces tests couvrent précisément
     * l'entre-deux que le trait VerifiesRegistration court-circuite.
     *
     * @return array{0: string, 1: array{membershipStatus: string, user: array{id: string}}}
     */
    private function registerAndVerify(string $email, string $ara, string $clubName): array
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], (string) json_encode([
            'email' => $email, 'password' => 'Password123!', 'firstName' => 'Ana', 'lastName' => 'Approb',
            'ara' => $ara, 'club_name' => $clubName, 'consent' => true,
        ]));
        self::assertResponseStatusCodeSame(202);

        // EM du CONTAINER COURANT (le kernel reboote entre deux requêtes — le EM de
        // setUp ne flushe pas ce que l'EmailVerifier du dernier kernel persiste).
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        \assert($user instanceof User);
        $pending = $em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        \assert($pending instanceof EmailVerificationToken);
        $raw = $container->get(EmailVerifier::class)->generateToken($user, $pending->getAra(), $pending->getClubName());
        $em->flush();

        $this->client->request('POST', '/api/register/verify', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], (string) json_encode(['token' => $raw]));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($body));

        return [$this->jwtFromCookie($this->client), $body];
    }

    private function pendingRequestFor(string $userId): ClubCreationRequest
    {
        $request = self::getContainer()->get(ClubCreationRequestRepository::class)->findPendingByUser($userId);
        \assert($request instanceof ClubCreationRequest);

        return $request;
    }

    /** Le stub FFBB partage son ARA entre suites : nettoyage avant/après. */
    private function deleteClubByAra(string $ara): void
    {
        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        if ($club instanceof Club) {
            $this->em->getConnection()->executeStatement('DELETE FROM club WHERE id = :id', ['id' => $club->getId()]);
        }
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }
}
