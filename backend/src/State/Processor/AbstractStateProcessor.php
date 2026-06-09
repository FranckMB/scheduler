<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class AbstractStateProcessor implements ProcessorInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
    ) {
    }

    abstract protected function getEntityClass(): string;
    abstract protected function createEntityFromInput(object $input): object;
    abstract protected function updateEntityFromInput(object $entity, object $input): void;
    abstract protected function mapEntityToOutput(object $entity): object;

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');

        if ($operation instanceof DeleteOperationInterface) {
            return $this->processDelete($uriVariables, $clubId);
        }

        $method = $operation->getMethod() ?? '';
        if ($method === 'POST') {
            return $this->processPost($data, $clubId, $seasonId);
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return $this->processPut($data, $uriVariables, $clubId, $seasonId);
        }

        return $data;
    }

    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        $entity = $this->createEntityFromInput($input);

        if ($clubId !== null && method_exists($entity, 'setClubId')) {
            $entity->setClubId($clubId);
        }
        if ($seasonId !== null && method_exists($entity, 'setSeasonId')) {
            $entity->setSeasonId($seasonId);
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->mapEntityToOutput($entity);
    }

    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        $id = $uriVariables['id'] ?? null;
        $entity = $this->entityManager->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new NotFoundHttpException('Resource not found');
        }

        if ($clubId !== null && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $this->updateEntityFromInput($entity, $input);
        $this->entityManager->flush();

        return $this->mapEntityToOutput($entity);
    }

    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $id = $uriVariables['id'] ?? null;
        $entity = $this->entityManager->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new NotFoundHttpException('Resource not found');
        }

        if ($clubId !== null && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}