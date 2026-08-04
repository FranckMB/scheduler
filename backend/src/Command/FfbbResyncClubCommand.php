<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Service\Basketball\FfbbClubPopulator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 / P2-18 — action support : ré-importe l'identité FFBB d'UN club (nom,
 * coordonnées, logo, comité/ligue) via FfbbClubPopulator en mode refresh —
 * exactement le service du bouton « Actualiser depuis la FFBB » de la fiche
 * club, déclenchable ici par le support sans attendre le gestionnaire.
 *
 * Connexion par défaut, pas la porte admin : `club` et les tables de référence
 * (FfbbLeague/FfbbCommittee, sans club_id) sont hors RLS — même chemin que le
 * hook async du register. ⚠ Les référentiels comité/ligue sont GLOBAUX : le
 * refresh met à jour des lignes que tous les clubs lisent (idempotent, tracé
 * par l'historique admin_job_run).
 */
#[AsCommand(
    name: 'app:clubs:ffbb-resync',
    description: 'Re-import a club\'s FFBB identity (name, contact, logo, committee/league). Support action (SA4).',
)]
final class FfbbResyncClubCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FfbbClubPopulator $populator,
    ) {
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

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            $io->error(\sprintf('Club %s not found.', $clubId));

            return Command::FAILURE;
        }

        if (!$this->populator->populate($club, refresh: true)) {
            // Code invalide ou organisme introuvable : le club n'a pas été touché.
            // ÉCHEC franc — un support qui déclenche l'action doit voir que rien
            // n'a été rafraîchi, pas un succès silencieux.
            $io->error(\sprintf('FFBB organisme not found for club %s (code "%s") — nothing refreshed.', $clubId, (string) $club->getFfbbClubCode()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Club %s resynced from FFBB (code %s).', $clubId, (string) $club->getFfbbClubCode()));

        return Command::SUCCESS;
    }
}
