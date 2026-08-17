<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Service\Basketball\FfbbClubPopulator;
use App\Service\Basketball\FfbbTeamImporter;
use App\Service\ClubProvisioner;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

/**
 * P2-4 — crée un CLUB DE DÉMONSTRATION avec le code FFBB du prospect, et y
 * repointe le compte « animateur démo ».
 *
 * L'effet waouw en rendez-vous (fondateur, 2026-08-06) : « je mets le code FFBB
 * et boum ça marche en direct » — fiche club, logo, comité/ligue remplis par la
 * FFBB (populate synchrone, best-effort comme au register), salles de la commune
 * en autocomplétion à l'étape Gymnases. Le club naît NON onboardé : le parcours
 * guidé du wizard EST la démo.
 *
 * UN SEUL login pour toutes les démos : l'animateur (créé au premier passage,
 * --animator-password requis ce jour-là) est DÉPLACÉ de démo en démo — ses
 * adhésions précédentes sont supprimées, jamais accumulées (le backend résout le
 * club depuis l'adhésion active du JWT : deux actives = ambigu).
 *
 * Ce chemin est le SEUL qui pose is_demo : aucun parcours utilisateur ne peut
 * fabriquer un club démo (horloge simulable, hors métriques, futur bridage exempté).
 */
#[AsCommand(
    name: 'app:demo:create',
    description: 'Create a DEMO club from a FFBB code and point the demo-animator account at it. Support action.',
)]
final class DemoCreateCommand extends Command
{
    private const string DEFAULT_ANIMATOR_EMAIL = 'demo@amateo.fr';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubProvisioner $clubProvisioner,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly FfbbClubPopulator $ffbbClubPopulator,
        private readonly FfbbTeamImporter $ffbbTeamImporter,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ffbb', null, InputOption::VALUE_REQUIRED, 'FFBB club code of the prospect (e.g. ARA0069036).');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Club display name (shown until the FFBB populate overwrites it).');
        $this->addOption('animator-email', null, InputOption::VALUE_REQUIRED, 'Demo animator login.', self::DEFAULT_ANIMATOR_EMAIL);
        $this->addOption('animator-password', null, InputOption::VALUE_REQUIRED, 'Required the FIRST time only (the animator account is created once).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ffbb = $input->getOption('ffbb');
        $name = $input->getOption('name');
        if (!\is_string($ffbb) || 1 !== preg_match('/^[A-Z]{3}\d{7}$/', $ffbb)) {
            $io->error('--ffbb must be a FFBB club code (3 letters + 7 digits, e.g. ARA0069036).');

            return Command::FAILURE;
        }
        if (!\is_string($name) || '' === trim($name)) {
            $io->error('--name is required.');

            return Command::FAILURE;
        }

        $email = strtolower((string) $input->getOption('animator-email'));
        $animator = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$animator instanceof User) {
            $password = $input->getOption('animator-password');
            if (!\is_string($password) || \strlen($password) < 12) {
                $io->error(\sprintf('Animator %s does not exist yet: --animator-password (min 12 chars) is required on first use.', $email));

                return Command::FAILURE;
            }
            $animator = new User;
            $animator->setEmail($email);
            $animator->setFirstName('Démo');
            $animator->setLastName('Amateo');
            $animator->setPasswordHash($this->passwordHasher->hashPassword($animator, $password));
            // Compte INTERNE : jamais passé par register, on matérialise ce que le
            // flux d'inscription aurait posé (login refusé sans email vérifié).
            $animator->setEmailVerifiedAt(new DateTimeImmutable);
            $animator->setTermsAcceptedAt(new DateTimeImmutable);
            $this->entityManager->persist($animator);
            $this->entityManager->flush();
            $io->note(\sprintf('Animator account %s created.', $email));
        }

        // Un ARA ne peut pas désigner deux clubs (contrainte du register) : si le
        // prospect a DÉJÀ un vrai club dans l'outil, on refuse — une démo ne doit
        // jamais squatter le code d'un client.
        $existing = $this->entityManager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ffbb]);
        if ($existing instanceof Club) {
            $io->error(\sprintf('Club "%s" already exists for %s%s — delete it first (or demo on it directly if it IS a demo).', $existing->getName(), $ffbb, $existing->isDemo() ? ' (demo)' : ''));

            return Command::FAILURE;
        }

        // L'animateur est DÉPLACÉ : ses adhésions précédentes partent (le backend
        // résout le club depuis l'adhésion ACTIVE du JWT — deux actives = ambigu).
        // club_user est une table RLS HYBRIDE (SEC-12) : la LECTURE cross-club est
        // ouverte hors contexte tenant — légitime ici — mais l'ÉCRITURE est
        // tenant-scopée : chaque DELETE se fait SOUS le contexte de SON club, sinon
        // la policy l'ignore en silence (attrapé par le test : l'adhésion survivait).
        foreach ($this->entityManager->getRepository(ClubUser::class)->findBy(['userId' => $animator->getId()]) as $membership) {
            $this->tenantConnectionContext->setClubId($membership->getClubId());

            try {
                $this->entityManager->remove($membership);
                $this->entityManager->flush();
            } finally {
                $this->tenantConnectionContext->clear();
            }
        }

        $club = $this->clubProvisioner->createClub(trim($name), $ffbb);
        $club->setIsDemo(true);
        $this->entityManager->flush();
        // Même séquence que l'approbation (ClubApprovalService) : GUC posé dès que
        // le club existe, puis adhésion + workspace sous ce contexte (RLS).
        $this->tenantConnectionContext->setClubId($club->getId());

        try {
            $this->clubProvisioner->createMembership($club->getId(), $animator->getId(), true, ClubRole::MANAGER);
            $this->clubProvisioner->seedWorkspace($club);
            $this->entityManager->flush();

            // Le « boum » : fiche + logo + comité/ligue depuis la FFBB, en direct.
            // Best-effort comme au register — une API FFBB muette ne casse pas la démo,
            // le nom saisi reste.
            try {
                $this->ffbbClubPopulator->populate($club);
                $this->entityManager->flush();
            } catch (Throwable $ffbbError) {
                $io->warning(\sprintf('FFBB populate failed (%s) — the demo club keeps the manual name.', $ffbbError->getMessage()));
            }

            // PR 3 du lot démo (2026-08-07) — les ÉQUIPES engagées, comme au vrai
            // register (PopulateClubFromFfbbHandler fait les deux étages) : sans
            // elles, la démo montrait une étape Équipes VIDE là où un vrai club
            // inscrit l'aurait pleine. Étage best-effort SÉPARÉ, même doctrine que
            // le handler : hors saison des poules (juin), 0 équipe = no-op naturel,
            // le prospect saisit comme tout le monde.
            try {
                $created = $this->ffbbTeamImporter->importEngagedTeams($club);
                $io->note(0 === $created
                    ? 'FFBB engagements: no team returned (pools not out yet?) — the Teams step starts empty.'
                    : \sprintf('FFBB engagements: %d team(s) created — the Teams step is pre-filled.', $created));
            } catch (Throwable $teamsError) {
                $io->warning(\sprintf('FFBB team import failed (%s) — enter teams manually.', $teamsError->getMessage()));
            }
        } finally {
            $this->tenantConnectionContext->clear();
        }

        $io->success(\sprintf('Demo club "%s" (%s) created — id %s. Log in as %s. Simulated clock: app:demo:clock --club=%s --date=YYYY-MM-DD.', $club->getName(), $ffbb, $club->getId(), $email, $club->getId()));

        return Command::SUCCESS;
    }
}
