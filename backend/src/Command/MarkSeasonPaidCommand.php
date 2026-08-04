<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 / P1-5 — action support : marque la saison SUIVANTE du club comme payée
 * (l'abonnement se paie PAR SAISON — décision fondateur 2026-08-04). C'est ce
 * marqueur que le gate de bascule (`SeasonTransitionService`) exige : sans lui,
 * générer sa saison suivante dès mai puis résilier était la fuite de revenu.
 * Relais manuel en attendant le paiement en ligne (P1-3).
 *
 * Idempotent : le marqueur ne recule jamais (max avec l'existant) — relancer
 * l'action dans l'année est un no-op annoncé. Connexion PAR DÉFAUT (pas la
 * porte admin) : `club` n'a pas de colonne club_id donc pas de policy RLS —
 * l'UPDATE ciblé par id passe, et le rail SA4 gate déjà l'accès.
 */
#[AsCommand(
    name: 'app:clubs:mark-season-paid',
    description: 'Mark the club\'s NEXT season as paid (per-season subscription, P1-5). Support action (SA4).',
)]
final class MarkSeasonPaidCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }

        // La saison « suivante » au sens du pivot système (15 juillet) : celle
        // que la prochaine bascule créera pour un club à jour.
        $nextYear = SeasonResolver::seasonYear(new DateTimeImmutable) + 1;

        $updated = $this->connection()->executeStatement(
            'UPDATE club SET paid_season_year = GREATEST(COALESCE(paid_season_year, 0), :year) WHERE id = :id',
            ['year' => $nextYear, 'id' => $clubId],
        );
        if (0 === $updated) {
            $io->error(\sprintf('Club %s not found.', $clubId));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Season %d-%d marked as paid for club %s — the transition gate is open.', $nextYear, $nextYear + 1, $clubId));

        return Command::SUCCESS;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
