<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\User;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * RGPD PR-3 non-regression (axe auth & memberships) : rétention des comptes.
 *
 * (a) inactif > 23 mois → préavis email + inactivityWarnedAt ;
 * (b) inactif > 24 mois + préavis ≥ 1 MOIS (la promesse de l'email) → anonymisé (routine erase : club
 *     orphelin programmé) ; JAMAIS anonymisé sans préavis suffisant ;
 * (c) un login réussi remet lastLoginAt et annule le préavis ;
 * (d) --dry-run n'écrit rien et n'envoie rien.
 */
#[Group('phase1')]
#[Group('integration')]
final class InactiveUsersRetentionTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testInactiveUserIsWarnedThenAnonymized(): void
    {
        [, $userId] = $this->registerVerified('INAA');
        $em = $this->em();

        // 25 mois d'inactivité d'emblée : le MÊME run doit warner SANS anonymiser
        // (garde same-run). last_login_at aussi : l'authenticator JWT trace
        // l'activité à chaque requête (le register l'a posée à maintenant).
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\' WHERE id = :id',
            ['id' => $userId],
        );

        // Étage 1 : préavis — et PAS d'anonymisation dans le même run.
        $tester = $this->commandTester();
        self::assertSame(0, $tester->execute([]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getInactivityWarnedAt(), 'préavis posé');
        self::assertNull($user->getAnonymizedAt(), 'same-run : jamais warn PUIS erase dans la même exécution');

        // Étage 2 : préavis vieux de 5 semaines (> 1 mois promis) → anonymisation.
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET inactivity_warned_at = NOW() - INTERVAL \'5 weeks\' WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(0, $this->commandTester()->execute([]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getAnonymizedAt(), 'anonymisé : 24 mois passés + préavis ≥ 1 mois');
        self::assertStringEndsWith('@anonymized.invalid', $user->getEmail());

        // La routine erase a programmé la purge du club orphelin.
        $clubId = $em->getConnection()->fetchOne('SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1', ['uid' => $userId]);
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNotNull($club->getErasureScheduledAt(), 'club orphelin programmé (+30 j)');
    }

    public function testFreshWarningBlocksAnonymizationEvenPast24Months(): void
    {
        [, $userId] = $this->registerVerified('INAB');
        $em = $this->em();

        // 25 mois d'inactivité mais préavis d'il y a 15 jours : l'email promet
        // UN MOIS — le compte garde son délai complet (cron down puis relancé).
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\', inactivity_warned_at = NOW() - INTERVAL \'15 days\' WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(0, $this->commandTester()->execute([]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getAnonymizedAt(), 'préavis < 1 mois → pas d\'anonymisation');
    }

    public function testSuccessfulLoginResetsInactivityTracking(): void
    {
        [, $userId, $email] = $this->registerVerified('INAC');
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET inactivity_warned_at = NOW() - INTERVAL \'5 days\' WHERE id = :id',
            ['id' => $userId],
        );

        $this->client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => 'Password123!'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getLastLoginAt(), 'login trace lastLoginAt');
        self::assertNull($user->getInactivityWarnedAt(), 'login annule le préavis');
    }

    public function testDryRunWritesNothing(): void
    {
        [, $userId] = $this->registerVerified('INAD');
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\' WHERE id = :id',
            ['id' => $userId],
        );

        self::assertSame(0, $this->commandTester()->execute(['--dry-run' => true]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getInactivityWarnedAt(), 'dry-run : pas de préavis posé');
        self::assertNull($user->getAnonymizedAt(), 'dry-run : pas d\'anonymisation');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:users:purge-inactive'));
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
            'firstName' => 'In', 'lastName' => 'Actif', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $email];
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
