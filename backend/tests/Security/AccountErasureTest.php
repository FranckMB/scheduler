<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * RGPD PR-1 non-regression (axe auth & memberships) : le droit à l'effacement.
 *
 * (a) un compte effacé ne peut plus s'authentifier (login KO, JWT inerte) ;
 * (b) dernier gestionnaire effacé → purge du club programmée (+30 j) ;
 * (c) un gestionnaire actif restant → club PAS programmé ;
 * (d) app:clubs:purge-erased vide le workspace du club échu SANS toucher un
 *     autre club (tenant) et ÉPARGNE l'identité publique FFBB (fiche club).
 */
#[Group('phase1')]
#[Group('integration')]
final class AccountErasureTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testErasedAccountCannotAuthenticateAnymore(): void
    {
        [$token, , $email] = $this->registerVerified('ERAA');

        // Confirmation exigée : un body sans le bon email est rejeté.
        $this->request('DELETE', '/api/me', $token, ['email' => 'wrong@test.fr']);
        self::assertResponseStatusCodeSame(400);

        $this->request('DELETE', '/api/me', $token, ['email' => $email]);
        self::assertResponseIsSuccessful();

        // Login avec les anciens identifiants → 401 (l'email n'existe plus).
        $this->client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => 'Password123!'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);

        // Le JWT encore en circulation est inerte : l'identité (email) ne
        // résout plus aucun compte.
        $this->request('GET', '/api/me', $token);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLastManagerErasureSchedulesTheClubPurge(): void
    {
        [$token, $userId, $email] = $this->registerVerified('ERAB');
        $em = $this->em();
        $clubId = $this->clubIdOf($userId);

        $this->request('DELETE', '/api/me', $token, ['email' => $email]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['clubPurgeScheduled']);

        $em->clear();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNotNull($club->getErasureScheduledAt(), 'dernier admin effacé → purge programmée');
        self::assertGreaterThan(new DateTimeImmutable('+29 days'), $club->getErasureScheduledAt(), 'délai de grâce ~30 j');

        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getAnonymizedAt());
        self::assertStringEndsWith('@anonymized.invalid', $user->getEmail());
        self::assertSame('Compte', $user->getFirstName());
    }

    public function testRemainingManagerPreventsTheClubPurge(): void
    {
        [$token, $userId, $email] = $this->registerVerified('ERAC');
        $em = $this->em();
        $clubId = $this->clubIdOf($userId);

        // Un second gestionnaire actif (inséré directement : le flux
        // d'approbation complet est couvert par MembershipTest).
        $other = new User;
        $other->setEmail('other-' . strtolower($email));
        $other->setFirstName('Autre');
        $other->setLastName('Admin');
        $other->setPasswordHash('x');
        $other->setEmailVerifiedAt(new DateTimeImmutable);
        $em->persist($other);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($other->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        $this->request('DELETE', '/api/me', $token, ['email' => $email]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['clubPurgeScheduled']);

        $em->clear();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNull($club->getErasureScheduledAt(), 'un admin actif reste → pas de purge programmée');
    }

    public function testPurgeErasedWipesTheWorkspaceSparesFfbbIdentityAndOtherClubs(): void
    {
        // Club A : programmé (échéance passée) + données workspace.
        [, $userA] = $this->registerVerified('ERAD');
        $clubA = $this->clubIdOf($userA);
        // Club B : témoin tenant, jamais programmé.
        [, $userB] = $this->registerVerified('ERAE');
        $clubB = $this->clubIdOf($userB);

        $em = $this->em();
        $seasonA = $this->currentSeasonId($clubA);
        $seasonB = $this->currentSeasonId($clubB);
        $this->insertCoach($clubA, $seasonA, 'CoachA');
        $this->insertCoach($clubB, $seasonB, 'CoachB');

        $club = $em->getRepository(Club::class)->find($clubA);
        self::assertInstanceOf(Club::class, $club);
        $club->setName('B CHARPENNES TEST');
        $club->setErasureScheduledAt(new DateTimeImmutable('-1 hour'));
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:clubs:purge-erased'));
        self::assertSame(0, $tester->execute([]));

        $em->clear();
        // Workspace A vidé (coach + saison), identité FFBB épargnée.
        self::assertSame(0, $this->countRows(Coach::class, $clubA), 'coachs du club A purgés');
        self::assertSame([], $em->getRepository(\App\Entity\Season::class)->findBy(['clubId' => $clubA]), 'saisons du club A purgées');
        $survivor = $em->getRepository(Club::class)->find($clubA);
        self::assertInstanceOf(Club::class, $survivor, 'la fiche club survit');
        self::assertSame('B CHARPENNES TEST', $survivor->getName(), 'identité (nom) épargnée');
        self::assertNotNull($survivor->getUnsubscribedAt());
        self::assertNull($survivor->getErasureScheduledAt(), 'programmation consommée');
        self::assertFalse($survivor->isOnboardingCompleted());

        // Club B intact (frontière tenant).
        self::assertSame(1, $this->countRows(Coach::class, $clubB), 'club B intact');
        self::assertNotSame([], $em->getRepository(\App\Entity\Season::class)->findBy(['clubId' => $clubB]));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, email]
     */
    private function registerVerified(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'E', 'lastName' => 'Rasure', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $email];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function clubIdOf(string $userId): string
    {
        $clubId = $this->em()->getConnection()->fetchOne(
            'SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1',
            ['uid' => $userId],
        );
        self::assertIsString($clubId);

        return $clubId;
    }

    private function currentSeasonId(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $seasonId = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid LIMIT 1',
            ['cid' => $clubId],
        );
        self::assertIsString($seasonId, 'le register/verify matérialise une saison');

        return $seasonId;
    }

    private function insertCoach(string $clubId, string $seasonId, string $name): void
    {
        // RLS : l'INSERT direct exige le GUC du club (WITH CHECK policy).
        $this->scopeGucToClub($clubId);
        $em = $this->em();
        $coach = new Coach;
        $coach->setClubId($clubId);
        $coach->setSeasonId($seasonId);
        $coach->setFirstName($name);
        $coach->setLastName('Test');
        $em->persist($coach);
        $em->flush();
        $em->clear();
    }

    /** @param class-string $entityClass */
    private function countRows(string $entityClass, string $clubId): int
    {
        // RLS : lire les lignes d'un club exige son GUC — sans lui le résultat
        // serait 0 pour tout le monde (faux vert).
        $this->scopeGucToClub($clubId);

        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
