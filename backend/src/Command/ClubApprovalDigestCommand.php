<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Service\ClubApprovalService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * P3-4 PR B — relances et expiration des demandes de création de club.
 *
 * Cadence fondateur (2026-08-05) : la demande expire à 7 jours ; le club est
 * relancé à 3 JOURS RESTANTS et LE JOUR J. Passée la date → statut `expired` :
 * le lien public meurt (404), mais la demande RESTE visible et approuvable en
 * console superadmin — « le superadmin peut valider si besoin ».
 *
 * Idempotent au jour près (`remindedAt` : au plus une relance par jour) ; les
 * demandes SANS mail club (introuvable à la FFBB) n'ont rien à relancer — leur
 * seule voie est la console. Quotidien (cron-runner, AdminJobCatalog),
 * `--dry-run` + `--date` pour répéter sans écrire. Table hors RLS (pas de
 * club_id) → aucun GUC.
 */
#[AsCommand(
    name: 'app:club-approvals:digest',
    description: 'Remind clubs of pending creation requests (3 days left + expiry day) and expire overdue ones. Runs daily (cron-runner).',
)]
final class ClubApprovalDigestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubApprovalService $approvalService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List reminders/expirations without sending or writing.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $dateOption = $input->getOption('date');
        $today = \is_string($dateOption) && '' !== $dateOption
            ? new DateTimeImmutable($dateOption)
            : new DateTimeImmutable('today');
        $todayKey = $today->format('Y-m-d');

        $reminded = 0;
        $expired = 0;
        foreach ($this->requests->findBy(['status' => ClubCreationRequest::STATUS_PENDING]) as $request) {
            $expiresKey = $request->getExpiresAt()->format('Y-m-d');

            // Échéance dépassée (le jour J reste OUVERT — sa relance part) :
            // le lien public meurt, la console superadmin prend le relais.
            if ($todayKey > $expiresKey) {
                ++$expired;
                $io->text(\sprintf('expire  %s (%s, échue le %s)', $request->getClubName(), $request->getAra(), $expiresKey));
                if (!$dryRun) {
                    $request->setStatus(ClubCreationRequest::STATUS_EXPIRED);
                    $request->setDecidedAt(null); // pas une décision — un délai
                }

                continue;
            }

            if (null === $request->getClubEmail()) {
                continue; // rien à relancer : la voie est la console superadmin
            }

            // Relances aux jours nommés par le fondateur : 3 jours restants + jour J.
            $daysLeft = (int) $today->diff($request->getExpiresAt()->setTime(0, 0))->format('%r%a');
            if (!\in_array($daysLeft, [3, 0], true)) {
                continue;
            }
            if ($request->getRemindedAt()?->format('Y-m-d') === $todayKey) {
                continue; // déjà relancé aujourd'hui (relance quotidienne idempotente)
            }
            $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
            if (!$requester instanceof User) {
                continue;
            }

            ++$reminded;
            $io->text(\sprintf('relance %s (%s, J-%d)', $request->getClubName(), $request->getAra(), $daysLeft));
            if (!$dryRun) {
                $this->approvalService->sendApprovalEmail($request, $requester, reminder: true);
                $request->setRemindedAt($today);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }
        $io->success(\sprintf('%s%d relance(s), %d expiration(s).', $dryRun ? '[dry-run] ' : '', $reminded, $expired));

        return Command::SUCCESS;
    }
}
