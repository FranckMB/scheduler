<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\UserResource;
use App\Dto\UserInput;
use App\Entity\User;
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
        private readonly Security $security,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return User::class;
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
     * @param User      $entity
     * @param UserInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
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
            throw new NotFoundHttpException('Resource not found');
        }
    }
}
