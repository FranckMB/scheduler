<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Message\Basketball\PopulateClubFromFfbbMessage;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbApiClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * P3-4 — le cycle de vie d'une demande de création de club (anti-squatting).
 *
 * Décisions fondateur (2026-08-05) :
 *  - la preuve du PREMIER gestionnaire = l'approbation du CLUB lui-même, via
 *    son mail institutionnel FFBB (tout gestionnaire y a accès) ;
 *  - mail FFBB introuvable (code inconnu de l'index, API muette) → la demande
 *    reste en attente, VISIBLE du superadmin (PR B) — jamais de création
 *    directe ;
 *  - expiration à 7 jours, relances à 3 jours restants et le jour J (PR B) ;
 *  - le superadmin peut approuver À TOUT MOMENT (gestionnaire parti fâché,
 *    boîte du club illisible) — même service, autre appelant.
 *
 * L'approbation matérialise le club (ClubProvisioner — la même vérité que
 * l'ancien verifyEmail) ; les AUTRES demandes en attente sur le même ARA
 * deviennent des adhésions pending (le club existe désormais : leur intention
 * « créer » est morte, leur intention « gérer ce club » reste).
 */
final class ClubApprovalService
{
    private const FROM = 'no-reply@clubscheduler.app';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubRepository $clubs,
        private readonly ClubProvisioner $provisioner,
        private readonly FfbbApiClient $ffbbApi,
        private readonly TenantConnectionContext $tenantContext,
        private readonly MailerInterface $mailer,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
    ) {}

    /**
     * Ouvre (ou réutilise) la demande de création de l'utilisateur pour cet ARA.
     * Appelé DANS la transaction de verifyEmail — persiste sans flush. L'envoi
     * du mail d'approbation est différé à sendApprovalEmail() (post-commit).
     */
    public function openRequest(User $user, string $ara, string $clubName): ClubCreationRequest
    {
        $existing = $this->requests->findPendingByUser($user->getId());
        if ($existing instanceof ClubCreationRequest && $existing->getAra() === $ara) {
            return $existing; // re-verify du même mail : une seule demande vivante
        }

        $request = new ClubCreationRequest;
        $request->setUserId($user->getId());
        $request->setAra($ara);
        $request->setClubName($clubName);
        $request->setClubEmail($this->lookupClubEmail($ara));
        $this->entityManager->persist($request);

        return $request;
    }

    /**
     * Mail « Approuver / Refuser » au mail institutionnel FFBB du club — appelé
     * APRÈS commit (le lien ne doit jamais référencer une demande non persistée).
     * Sans mail connu : rien à envoyer, la demande attend le superadmin (PR B).
     */
    public function sendApprovalEmail(ClubCreationRequest $request, User $requester, bool $reminder = false): void
    {
        $clubEmail = $request->getClubEmail();
        if (null === $clubEmail) {
            return;
        }
        $link = rtrim($this->frontendBaseUrl, '/') . '/club-approval/' . $request->getToken();
        $requesterName = trim($requester->getFirstName() . ' ' . $requester->getLastName());
        try {
            $this->mailer->send(
                (new Email)
                    ->from(self::FROM)
                    ->to($clubEmail)
                    ->subject(($reminder ? 'Rappel — ' : '') . \sprintf('%s demande à créer l\'espace ClubScheduler de %s', $requesterName, $request->getClubName()))
                    ->text(\sprintf(
                        "Bonjour,\n\n%s (%s) demande à créer et gérer l'espace ClubScheduler du club %s (code FFBB %s).\n\nSi cette personne est bien un gestionnaire de votre club, approuvez sa demande :\n%s\n\nSinon, refusez-la depuis la même page. Sans réponse, la demande expire le %s.\n\nClubScheduler",
                        $requesterName,
                        $requester->getEmail(),
                        $request->getClubName(),
                        $request->getAra(),
                        $link,
                        $request->getExpiresAt()->format('d/m/Y'),
                    )),
            );
        } catch (Throwable $e) {
            // Best-effort : la demande vit, le superadmin reste la voie de secours.
            $this->logger->warning('Club approval email failed', ['requestId' => $request->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Approbation → le club EXISTE (provisioning complet + populate async), la
     * demande est close, les demandes concurrentes sur le même ARA deviennent
     * des adhésions pending. Transactionnel, GUC posé/relâché ici.
     *
     * @return Club le club créé (ou rejoint, si l'ARA a été créé entre-temps)
     */
    public function approve(ClubCreationRequest $request): Club
    {
        $newClubId = null;
        try {
            $club = $this->entityManager->wrapInTransaction(function () use ($request, &$newClubId): Club {
                // Sérialise les approbations concurrentes du même ARA (deux onglets,
                // club-mail + superadmin) — même patron que la bascule de saison.
                $this->entityManager->getConnection()->executeStatement(
                    'SELECT pg_advisory_xact_lock(hashtext(:key))',
                    ['key' => 'club-approval:' . $request->getAra()],
                );

                $existing = $this->clubs->findOneBy(['ffbbClubCode' => $request->getAra()]);
                if ($existing instanceof Club) {
                    // Le club est né entre-temps (autre demande approuvée) : cette
                    // demande devient une adhésion pending — jamais un 2e club.
                    $this->tenantContext->setClubId($existing->getId());
                    $this->provisioner->createMembership($existing->getId(), $request->getUserId(), false);
                    $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                    return $existing;
                }

                $club = $this->provisioner->createClub($request->getClubName(), $request->getAra());
                $this->tenantContext->setClubId($club->getId());
                $this->provisioner->createMembership($club->getId(), $request->getUserId(), true);
                $this->provisioner->seedWorkspace($club);
                $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                // Les demandes concurrentes sur le même ARA : leur « créer » est mort,
                // leur « gérer ce club » reste → adhésion pending, approuvable par le
                // premier gestionnaire (flux existant). ⚠ La demande approuvée est
                // encore `pending` EN BASE (close() non flushé) : l'exclure, sinon
                // son auteur recevrait un second membership (unique violation).
                foreach ($this->requests->findPendingByAra($request->getAra()) as $sibling) {
                    if ($sibling->getId() === $request->getId()) {
                        continue;
                    }
                    $this->provisioner->createMembership($club->getId(), $sibling->getUserId(), false);
                    $this->close($sibling, ClubCreationRequest::STATUS_APPROVED);
                }

                $newClubId = $club->getId();

                return $club;
            });
        } finally {
            $this->tenantContext->clear();
        }

        // Lot C (même contrat que verifyEmail) : autofill FFBB async, APRÈS commit.
        if (null !== $newClubId) {
            $this->messageBus->dispatch(new PopulateClubFromFfbbMessage($newClubId));
        }

        $this->notifyRequester($request, approved: true);

        return $club;
    }

    public function refuse(ClubCreationRequest $request): void
    {
        $this->close($request, ClubCreationRequest::STATUS_REFUSED);
        $this->entityManager->flush();
        $this->notifyRequester($request, approved: false);
    }

    private function close(ClubCreationRequest $request, string $status): void
    {
        $request->setStatus($status);
        $request->setDecidedAt(new DateTimeImmutable);
    }

    private function notifyRequester(ClubCreationRequest $request, bool $approved): void
    {
        $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
        if (!$requester instanceof User) {
            return;
        }
        try {
            $this->mailer->send(
                (new Email)
                    ->from(self::FROM)
                    ->to($requester->getEmail())
                    ->subject($approved ? \sprintf('Votre espace %s est prêt', $request->getClubName()) : \sprintf('Demande refusée — %s', $request->getClubName()))
                    ->text($approved
                        ? \sprintf("Bonjour,\n\nVotre demande a été approuvée : l'espace ClubScheduler de %s est créé et vous en êtes gestionnaire.\n\nConnectez-vous : %s\n\nClubScheduler", $request->getClubName(), rtrim($this->frontendBaseUrl, '/') . '/login')
                        : \sprintf("Bonjour,\n\nVotre demande de création de l'espace %s a été refusée par le club.\n\nSi vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club.\n\nClubScheduler", $request->getClubName())),
            );
        } catch (Throwable $e) {
            $this->logger->warning('Club approval requester notification failed', ['requestId' => $request->getId(), 'error' => $e->getMessage()]);
        }
    }

    /** Le mail institutionnel FFBB du club — best-effort, null = file superadmin. */
    private function lookupClubEmail(string $ara): ?string
    {
        if (!FfbbApiClient::isValidClubCode($ara)) {
            return null;
        }
        try {
            foreach ($this->ffbbApi->search($ara) as $hit) {
                if (0 === strcasecmp((string) ($hit['code'] ?? ''), $ara)) {
                    $mail = $hit['mail'] ?? null;

                    return \is_string($mail) && false !== filter_var(trim($mail), \FILTER_VALIDATE_EMAIL) ? trim($mail) : null;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('FFBB club email lookup failed', ['ara' => $ara, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
