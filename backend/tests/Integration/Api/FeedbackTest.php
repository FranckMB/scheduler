<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Feedback;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * P5-6 PR 1 — le canal de signalement côté membre (POST /api/feedback).
 *
 * Prouve : l'anonyme est refusé ; un MEMBRE simple (non-gestionnaire) écrit (aucune
 * garde management) et sa ligne atterrit au bon club/user ; les validations (topic,
 * message vide/trop long) ; la COPIE serveur du contexte lourd d'un planning nommé,
 * et surtout que ce chemin de copie est tenant-étanche — un scheduleId d'un AUTRE
 * club fait échouer le POST ENTIER (404, rien créé) ; l'accusé de réception enfilé
 * sur le bus ; et la borne 10/h par utilisateur.
 */
#[Group('integration')]
final class FeedbackTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testAnonymousCannotSubmit(): void
    {
        $this->client->request('POST', '/api/feedback', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['topic' => 'bug', 'message' => 'anon'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }

    public function testPlainMemberSubmitsAndTheRowLandsAtTheRightClubAndUser(): void
    {
        [$token, $userId, $clubId] = $this->registerVerifiedMember('FBM1');

        $this->post($token, ['topic' => 'idea', 'message' => 'Un tri par gymnase serait utile.']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseBody()['id'] ?? null;
        self::assertIsString($id);

        $row = $this->feedbackRow($clubId, $id);
        self::assertSame($clubId, $row['club_id'], 'le signalement est rattaché au club courant');
        self::assertSame($userId, $row['user_id'], 'et à son auteur');
        self::assertSame('idea', $row['topic']);
        self::assertSame('untreated', $row['status'], 'un signalement naît non traité');
    }

    public function testInvalidTopicIsRejected(): void
    {
        [$token] = $this->registerVerifiedMember('FBM2');

        $this->post($token, ['topic' => 'rant', 'message' => 'peu importe']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEmptyMessageIsRejected(): void
    {
        [$token] = $this->registerVerifiedMember('FBM3');

        $this->post($token, ['topic' => 'bug', 'message' => '   ']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTooLongMessageIsRejected(): void
    {
        [$token] = $this->registerVerifiedMember('FBM4');

        $this->post($token, ['topic' => 'bug', 'message' => str_repeat('a', 5001)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSameClubScheduleCopiesSnapshotAndDiagnostics(): void
    {
        [$token, , $clubId] = $this->registerVerifiedMember('FBM5');
        $seasonId = $this->currentSeasonId($clubId);
        $scheduleId = $this->seedCompletedSchedule($clubId, $seasonId);

        $this->post($token, [
            'topic' => 'bug',
            'message' => 'Un créneau se chevauche.',
            'context' => ['screen' => 'planning', 'scheduleId' => $scheduleId],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseBody()['id'];

        $context = $this->contextOf($clubId, $id);
        self::assertSame('planning', $context['screen'] ?? null, 'le contexte léger est conservé');
        self::assertSame($scheduleId, $context['scheduleId'] ?? null);
        self::assertNotEmpty($context['snapshot'] ?? [], 'le snapshot du planning est recopié côté serveur');
        self::assertNotEmpty($context['diagnostics'] ?? [], 'les diagnostics du planning sont recopiés');
        self::assertSame('COMPLETED', $context['scheduleStatus'] ?? null);
    }

    public function testForeignClubScheduleIs404AndCreatesNoFeedback(): void
    {
        // Club B : le planning-cible, semé dans un club dont l'auteur n'est PAS membre.
        [, , $clubB] = $this->registerVerifiedMember('FBM6B');
        $seasonB = $this->currentSeasonId($clubB);
        $foreignScheduleId = $this->seedCompletedSchedule($clubB, $seasonB);

        // Club A : l'auteur. On repart d'un navigateur neuf (l'identité change).
        $this->startFreshBrowserSession($this->client);
        [$tokenA, , $clubA] = $this->registerVerifiedMember('FBM6A');

        $this->post($tokenA, [
            'topic' => 'bug',
            'message' => 'Je vise le planning d\'un autre club.',
            'context' => ['scheduleId' => $foreignScheduleId],
        ]);
        self::assertResponseStatusCodeSame(404, 'un scheduleId étranger → 404');

        self::assertSame(0, $this->feedbackCount($clubA), 'aucun signalement créé pour l\'auteur');
        self::assertSame(0, $this->feedbackCount($clubB), 'ni pour le club-cible');
    }

    public function testAcknowledgementEmailIsQueuedOnTheBus(): void
    {
        [$token] = $this->registerVerifiedMember('FBM7');

        $this->post($token, ['topic' => 'idea', 'message' => 'Merci pour l\'app.']);
        self::assertResponseStatusCodeSame(201);

        // Le transport mémoire est remis à zéro à chaque frontière de requête : il ne
        // reflète QUE le POST ci-dessus. Le mail n'est pas parti, il a été enfilé.
        self::assertCount(1, $this->queuedEmails(), 'l\'accusé « bien reçu » est enfilé sur le bus');
    }

    public function testEleventhSubmissionByTheSameUserIs429(): void
    {
        [$token, $userId] = $this->registerVerifiedMember('FBM8');
        $this->resetFeedbackLimiterFor($userId);

        $statuses = [];
        for ($i = 0; $i < 11; ++$i) {
            $this->post($token, ['topic' => 'bug', 'message' => 'spam ' . $i]);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertNotSame(429, $statuses[0], 'la première reste dans le budget');
        self::assertSame(429, $statuses[10], 'la 11ᵉ dépasse la borne 10/h et doit être throttlée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Registre + vérifie un compte, puis rétrograde son membership en MEMBRE simple
     * (non-gestionnaire) — l'endpoint n'a pas de garde management, ce rôle doit écrire.
     *
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function registerVerifiedMember(string $ara): array
    {
        [$token, $userId, $clubId] = $this->registerVerified($ara);

        // Rétrograder en 'member' directement (le flux d'approbation a ses propres NR) :
        // on veut prouver que le NON-gestionnaire écrit, pas re-tester l'attribution.
        $this->scopeGucToClub($clubId);
        $this->em()->getConnection()->executeStatement(
            'UPDATE club_user SET role = :role WHERE club_id = :cid AND user_id = :uid',
            ['role' => 'member', 'cid' => $clubId, 'uid' => $userId],
        );
        $this->clearGuc();

        return [$token, $userId, $clubId];
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
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
            'firstName' => 'Mem', 'lastName' => 'Bre', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($me));
        $userId = $me['id'];
        self::assertIsString($userId);

        $clubId = $this->em()->getConnection()->fetchOne(
            'SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1',
            ['uid' => $userId],
        );
        self::assertIsString($clubId);

        return [$token, $userId, $clubId];
    }

    private function currentSeasonId(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $seasonId = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid LIMIT 1',
            ['cid' => $clubId],
        );
        $this->clearGuc();
        self::assertIsString($seasonId, 'le register/verify matérialise une saison');

        return $seasonId;
    }

    /** Un planning COMPLETED avec snapshot + un diagnostic, inséré directement sous RLS. */
    private function seedCompletedSchedule(string $clubId, string $seasonId): string
    {
        $this->scopeGucToClub($clubId);
        $em = $this->em();

        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setSchedulePlanId(Uuid::v4()->toRfc4122());
        $schedule->setName('Planning test');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $schedule->setSnapshotData(['weeks' => [['index' => 1]], 'teams' => ['A', 'B']]);
        $em->persist($schedule);

        $diagnostic = new ScheduleDiagnostic;
        $diagnostic->setClubId($clubId);
        $diagnostic->setSeasonId($seasonId);
        $diagnostic->setScheduleId($schedule->getId());
        $diagnostic->setType('VENUE_OVERBOOKED');
        $diagnostic->setSeverity(ScheduleDiagnosticSeverity::WARNING);
        $diagnostic->setMessage('Un gymnase est sur-réservé.');
        $diagnostic->setSuggestions(['Libérer un créneau']);
        $em->persist($diagnostic);

        $em->flush();
        $scheduleId = $schedule->getId();
        $em->clear();
        $this->clearGuc();

        return $scheduleId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $token, array $body): void
    {
        $this->client->request('POST', '/api/feedback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function feedbackRow(string $clubId, string $id): array
    {
        $this->scopeGucToClub($clubId);
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT club_id, user_id, topic, status FROM feedback WHERE id = :id',
            ['id' => $id],
        );
        $this->clearGuc();
        self::assertIsArray($row, 'le signalement doit exister en base');

        return $row;
    }

    /** @return array<string, mixed> */
    private function contextOf(string $clubId, string $id): array
    {
        $this->scopeGucToClub($clubId);
        $raw = $this->em()->getConnection()->fetchOne('SELECT context FROM feedback WHERE id = :id', ['id' => $id]);
        $this->clearGuc();
        self::assertIsString($raw, 'le contexte doit être stocké');
        $context = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($context);

        return $context;
    }

    private function feedbackCount(string $clubId): int
    {
        $this->scopeGucToClub($clubId);
        $count = (int) $this->em()->getConnection()->fetchOne(
            'SELECT count(*) FROM feedback WHERE club_id = :cid',
            ['cid' => $clubId],
        );
        $this->clearGuc();

        return $count;
    }

    /** @return list<object> the queued SendEmailMessage instances */
    private function queuedEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent());
    }

    private function resetFeedbackLimiterFor(string $userId): void
    {
        $factory = self::getContainer()->get('limiter.feedback');
        \assert($factory instanceof RateLimiterFactory);
        $factory->create($userId)->reset();
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
