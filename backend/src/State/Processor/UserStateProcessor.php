<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\UserResource;
use App\Dto\UserInput;
use App\Entity\User;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @extends AbstractStateProcessor<User, UserInput, UserResource>
 */
class UserStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly Security $security,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return User::class;
    }

    /**
     * P1-1 opt-out: editing a User is self-only, NOT a management action. A
     * plain member edits its OWN profile (assertSelf → 404 on any other id,
     * SEC-02 / UserSelfOnlyTest). Requiring a management role here would lock
     * non-management members out of their own account, so this stays false.
     */
    protected function requiresManagementRole(): bool
    {
        return false;
    }

    /**
     * SEC-02: a user may only modify its own record; any other id → 404.
     *
     * @param UserInput            $input
     * @param array<string, mixed> $uriVariables
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        $this->assertSelf($uriVariables['id'] ?? null);

        return parent::processPut($input, $uriVariables, $clubId, $seasonId);
    }

    /**
     * @param UserInput $input
     */
    protected function createEntityFromInput(object $input): User
    {
        $entity = new User;
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }

        return $entity;
    }

    /**
     * P4-74 : l'e-mail n'est PLUS muté ici. Le changer en direct basculerait
     * l'identité (email = identifiant de connexion) sans confirmer la nouvelle
     * adresse. Le champ `email` d'un PUT est donc IGNORÉ en silence (choix : ce
     * chemin ressource est legacy — l'UI passe par PATCH /api/me, qui renvoie un
     * 422 explicite ; l'ignorer ici ne casse aucun test PUT existant). Le vrai
     * changement passe par « confirmer d'abord, basculer ensuite » (POST /api/me/email).
     *
     * @param User      $entity
     * @param UserInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }
    }

    /**
     * @param User $entity
     */
    protected function mapEntityToOutput(object $entity): UserResource
    {
        return UserResource::fromEntity($entity);
    }

    private function assertSelf(mixed $id): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || $id !== $user->getId()) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
    }
}
