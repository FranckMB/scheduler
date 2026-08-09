<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 / P1-3 — attribution superadmin d'une OFFRE à un club (virement encaissé →
 * offre posée). Cible un plan par son CODE stable (`decouverte`, `essentiel`,
 * `club`, `grand-club`, `sans-limite`, `beta`) : l'UI console liste dynamiquement
 * une entrée figée PAR offre (AdminActionCatalog).
 *
 * N'ouvre pas à elle seule le droit payant : l'offre effective exige aussi une
 * saison réglée (action « Marquer la saison suivante payée », app:clubs:mark-season-paid).
 * Ce partage tient l'invariant PlanEntitlements — une offre non réglée retombe sur
 * Découverte à la lecture.
 *
 * Connexion PAR DÉFAUT (même patron que MarkSeasonPaidCommand) : `club` n'a pas de
 * colonne club_id, donc pas de policy RLS — l'UPDATE ciblé par id passe, et le rail
 * SA4 gate déjà l'accès.
 */
#[AsCommand(
    name: 'app:clubs:set-plan',
    description: 'Assign a subscription offer (by code) to a club (P1-3). Support action (SA4).',
)]
final class SetClubPlanCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Offer code (decouverte|essentiel|club|grand-club|sans-limite|beta).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        $planCode = $input->getOption('plan');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }
        if (!\is_string($planCode) || '' === $planCode) {
            $io->error('--plan <code> is required.');

            return Command::FAILURE;
        }

        $connection = $this->connection();
        $planId = $connection->fetchOne(
            'SELECT id FROM subscription_plan WHERE code = :code',
            ['code' => $planCode],
        );
        if (false === $planId) {
            $io->error(\sprintf('Unknown offer code "%s".', $planCode));

            return Command::FAILURE;
        }

        $updated = $connection->executeStatement(
            'UPDATE club SET plan_id = :planId WHERE id = :id',
            ['planId' => $planId, 'id' => $clubId],
        );
        if (0 === $updated) {
            $io->error(\sprintf('Club %s not found.', $clubId));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Offer "%s" assigned to club %s.', $planCode, $clubId));

        return Command::SUCCESS;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
