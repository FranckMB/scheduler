<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RGPD — droit à l'effacement (responsable de traitement, comptes User).
 *
 * L'effacement d'un compte est une ANONYMISATION immédiate (l'email devient
 * introuvable → le JWT en cours et tout login futur sont inertes), jamais un
 * DELETE : ClubUser garde une ligne inactive pointant sur un User vidé, ce qui
 * préserve l'intégrité référentielle sans conserver de donnée personnelle.
 *
 * Si le compte effacé était le DERNIER membre management actif d'un club, le
 * workspace de ce club n'a plus de responsable : sa purge est PROGRAMMÉE
 * (erasureScheduledAt = +30 j, délai de grâce annulable) et exécutée par
 * app:clubs:purge-erased. L'identité publique FFBB du club survit à la purge
 * (voir ErasedClubPurger).
 */
final class AccountErasureService
{
    public const GRACE_PERIOD = '+30 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {}

    /**
     * Anonymise le compte et programme la purge des clubs orphelins.
     *
     * @return list<string> ids des clubs dont la purge vient d'être programmée
     */
    public function erase(User $user): array
    {
        $now = new DateTimeImmutable;

        // 1. Tokens rattachés au compte (vérification email, reset password) —
        //    supprimés : ils portent l'email/l'identité.
        $this->entityManager->createQueryBuilder()
            ->delete(EmailVerificationToken::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()->execute();
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()->execute();

        // 2. Memberships désactivés AVANT le comptage des clubs orphelins.
        //    club_user se LIT hors tenant (policy SELECT USING(true)) mais son
        //    UPDATE est tenant-gardé (WITH CHECK sur le GUC) : un UPDATE global
        //    sauterait silencieusement les memberships des AUTRES clubs d'un
        //    user multi-club → on scope le GUC club par club.
        $clubIds = $this->clubUserRepository->findActiveClubIds($user->getId());
        foreach ($clubIds as $clubId) {
            $this->tenantConnectionContext->setClubId($clubId);
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE club_user SET is_active = false, updated_at = NOW() WHERE user_id = :uid AND club_id = :cid',
                ['uid' => $user->getId(), 'cid' => $clubId],
            );
        }
        $this->tenantConnectionContext->clear();

        // 3. Anonymisation : l'email devient un jeton non-adressable, le hash un
        //    aléa jamais valide. random_bytes garantit qu'aucun mot de passe ne
        //    matchera jamais ce "hash" (ce n'est pas un hash bcrypt/argon).
        $user->setEmail(\sprintf('deleted-%s@anonymized.invalid', $user->getId()));
        $user->setFirstName('Compte');
        $user->setLastName('Supprimé');
        $user->setPasswordHash(bin2hex(random_bytes(32)));
        $user->setAnonymizedAt($now);
        $this->entityManager->flush();

        // 4. Clubs orphelins : plus AUCUN membre management actif → purge du
        //    workspace programmée à +30 j (annulable en remettant le champ à
        //    null tant que app:clubs:purge-erased n'est pas passé).
        $scheduled = [];
        foreach ($clubIds as $clubId) {
            if ($this->hasActiveManager($clubId)) {
                continue;
            }
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if ($club instanceof Club && null === $club->getErasureScheduledAt()) {
                $club->setErasureScheduledAt($now->modify(self::GRACE_PERIOD));
                $scheduled[] = $clubId;
            }
        }
        $this->entityManager->flush();

        return $scheduled;
    }

    private function hasActiveManager(string $clubId): bool
    {
        // findManagementEmails porte déjà la liste canonique des rôles
        // management (source unique, SEC-07) — on compte, on n'utilise pas
        // les emails.
        return [] !== $this->clubUserRepository->findManagementEmails($clubId);
    }
}
