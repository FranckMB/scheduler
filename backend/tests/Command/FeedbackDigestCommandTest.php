<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\FeedbackDigestCommand;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * P5-6 PR 2 — le digest quotidien des signalements au support.
 *
 * Garde : la fenêtre [J-1, J) (ni le jour même, ni l'avant-veille) ; le compteur
 * global des non-traités ; l'extrait du message borné à 200 caractères ; digest
 * VIDE → aucun email ; SUPPORT_EMAIL vide → no-op ; l'exit non-zéro ne signale que
 * l'échec de DISPATCH.
 *
 * `feedback` est FORCE RLS : on sème et on lit par la connexion `admin` (hors DAMA).
 * On ouvre une transaction dessus, on vide la table pour un décor déterministe, et
 * on `rollBack` au teardown — rien de committé ne fuit (patron AdminCapacityServiceTest).
 */
#[Group('integration')]
final class FeedbackDigestCommandTest extends KernelTestCase
{
    private Connection $admin;

    private bool $inTransaction = false;

    public function testSendsYesterdayDigestWithBoundedExcerptAndUntreatedCount(): void
    {
        $club = $this->seedClub('Golf');
        $longMessage = str_repeat('a', 250);
        // Dans la fenêtre [J-1, J) — J = 2026-08-13.
        $this->seedFeedback($club, 'bug', $longMessage, 'untreated', '2026-08-12T09:00:00+00:00');
        // Hors fenêtre : le jour même, et l'avant-veille.
        $this->seedFeedback($club, 'idea', 'aujourd\'hui', 'untreated', '2026-08-13T09:00:00+00:00');
        $this->seedFeedback($club, 'bug', 'avant-veille', 'treated', '2026-08-10T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertCount(1, $mailer->sent, 'un seul digest, pour la veille');
        $email = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('support@example.test', $email->getTo()[0]->getAddress());
        $body = $email->getTextBody() ?? '';
        // Extrait borné : 200 « a » + ellipse, jamais les 250.
        self::assertStringContainsString(str_repeat('a', 200) . '…', $body);
        self::assertStringNotContainsString(str_repeat('a', 201), $body);
        // Compteur global des non-traités : les deux untreated (veille + jour même).
        self::assertStringContainsString('En attente de traitement (total) : 2', $body);
    }

    public function testEmptyWindowSendsNoEmail(): void
    {
        $club = $this->seedClub('Hotel');
        // Rien dans la fenêtre : seulement le jour même.
        $this->seedFeedback($club, 'bug', 'aujourd\'hui', 'untreated', '2026-08-13T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertSame([], $mailer->sent, 'digest vide → aucun email');
    }

    public function testEmptySupportEmailIsANoOp(): void
    {
        $club = $this->seedClub('India');
        $this->seedFeedback($club, 'bug', 'à envoyer', 'untreated', '2026-08-12T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, ''));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertSame([], $mailer->sent, 'sans adresse support : no-op, aucun envoi');
        self::assertStringContainsString('aucune adresse support', $tester->getDisplay());
    }

    public function testDispatchFailureIsANonZeroExit(): void
    {
        $club = $this->seedClub('Juliett');
        $this->seedFeedback($club, 'bug', 'à envoyer', 'untreated', '2026-08-12T09:00:00+00:00');

        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new RuntimeException('bus down');
            }
        };
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));

        self::assertSame(Command::FAILURE, $tester->execute(['--date' => '2026-08-13']));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $connection = $registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $this->admin = $connection;
        $this->admin->beginTransaction();
        $this->inTransaction = true;
        $this->admin->executeStatement('DELETE FROM feedback');
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    private function command(MailerInterface $mailer, string $supportEmail): FeedbackDigestCommand
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);

        return new FeedbackDigestCommand($registry, $mailer, $supportEmail);
    }

    /** @return MailerInterface&object{sent: list<RawMessage>} */
    private function recordingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
    }

    private function seedClub(string $name): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $suffix = strtolower(substr(md5($clubId), 0, 8));
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE)',
            ['id' => $clubId, 'name' => $name, 'slug' => 'dig-' . $suffix, 'tz' => 'Europe/Paris', 'locale' => 'fr'],
        );

        return $clubId;
    }

    private function seedFeedback(string $clubId, string $topic, string $message, string $status, string $createdAt): void
    {
        $this->admin->executeStatement(
            'INSERT INTO feedback (id, version, club_id, user_id, topic, message, context, status, treated_at, created_at) VALUES (:id, 1, :club, :user, :topic, :message, NULL, :status, NULL, :created)',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'club' => $clubId,
                'user' => Uuid::v4()->toRfc4122(),
                'topic' => $topic,
                'message' => $message,
                'status' => $status,
                'created' => $createdAt,
            ],
        );
    }
}
