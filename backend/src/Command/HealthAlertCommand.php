<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AdminAlertStateStore;
use App\Service\AdminDataFreshnessService;
use App\Service\AdminHealthService;
use App\Service\HealthAlertEvaluator;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Alerting minimal (console superadmin) : vérifie les MÊMES sondes que le dashboard
 * (AdminHealthService) + la fraîcheur des référentiels, et EMAILE les superadmins
 * actifs quand un check passe au rouge — « tu apprends la panne avant tes
 * utilisateurs ». Anti-spam par état (AdminAlertStateStore) : une alerte à l'entrée
 * d'incident, un email de rétablissement au retour, silence entre les deux.
 * Cadence : toutes les 10 minutes (catalogue SA3, cron-runner).
 */
#[AsCommand(
    name: 'app:health:alert',
    description: 'Check health probes + data freshness and email superadmins on red transitions. Runs every 10 minutes (cron-runner).',
)]
final class HealthAlertCommand extends Command
{
    private const FROM_ADDRESS = 'ClubScheduler <no-reply@clubscheduler.fr>';

    public function __construct(
        private readonly AdminHealthService $healthService,
        private readonly AdminDataFreshnessService $freshnessService,
        private readonly HealthAlertEvaluator $evaluator,
        private readonly AdminAlertStateStore $stateStore,
        private readonly MailerInterface $mailer,
        private readonly ManagerRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $health = $this->healthService->health();
        $freshness = $this->freshnessService->referentials();
        $solver = $this->solverLast24h();

        $alerts = $this->evaluator->evaluate($health, $freshness, $solver);
        $diff = $this->stateStore->transition($alerts);

        if ([] === $diff['fired'] && [] === $diff['recovered']) {
            $io->writeln(\sprintf('No transition (%d check(s) currently firing).', \count($alerts)));

            return Command::SUCCESS;
        }

        $recipients = $this->recipients();
        if ([] === $recipients) {
            // Pas de destinataire ≠ échec du check : l'état est déjà posé, on le signale.
            $io->warning('Alert transitions detected but no enabled superadmin to notify.');

            return Command::SUCCESS;
        }

        if ([] !== $diff['fired']) {
            $lines = array_map(static fn (array $alert): string => '• ' . $alert['message'], $diff['fired']);
            $this->send($recipients, \sprintf('🔴 ClubScheduler — %d alerte(s)', \count($diff['fired'])), implode("\n", $lines));
            $io->writeln(\sprintf('Alert email sent (%d check(s) newly firing).', \count($diff['fired'])));
        }

        if ([] !== $diff['recovered']) {
            $lines = array_map(static fn (string $key): string => '• ' . $key, $diff['recovered']);
            $this->send($recipients, \sprintf('🟢 ClubScheduler — %d check(s) rétabli(s)', \count($diff['recovered'])), "De nouveau au vert :\n" . implode("\n", $lines));
            $io->writeln(\sprintf('Recovery email sent (%d check(s) back to ok).', \count($diff['recovered'])));
        }

        return Command::SUCCESS;
    }

    /** @return array{generations24h: int, infeasible24h: int} */
    private function solverLast24h(): array
    {
        $row = $this->admin()->fetchAssociative(
            'SELECT COUNT(*) AS generations, COUNT(*) FILTER (WHERE status = \'INFEASIBLE\') AS infeasible FROM solver_metrics WHERE created_at >= NOW() - INTERVAL \'24 hours\'',
        );

        return [
            'generations24h' => false === $row ? 0 : (int) ($row['generations'] ?? 0),
            'infeasible24h' => false === $row ? 0 : (int) ($row['infeasible'] ?? 0),
        ];
    }

    /**
     * Les superadmins ACTIFS sont les destinataires — zéro configuration nouvelle :
     * qui peut ouvrir la console reçoit ses alertes.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        $emails = $this->admin()->fetchFirstColumn('SELECT email FROM super_admin WHERE enabled = TRUE ORDER BY email');

        return array_values(array_filter($emails, static fn (mixed $email): bool => \is_string($email) && '' !== $email));
    }

    /** @param list<string> $recipients */
    private function send(array $recipients, string $subject, string $body): void
    {
        $email = (new Email)
            ->from(self::FROM_ADDRESS)
            ->subject($subject)
            ->text($body . "\n\nConsole : /admin");
        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }
        $this->mailer->send($email);
    }

    private function admin(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
