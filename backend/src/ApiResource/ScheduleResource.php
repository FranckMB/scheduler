<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\ScheduleInput;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\State\Processor\ScheduleStateProcessor;
use App\State\Provider\ScheduleStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Schedule', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
    new Post(
        uriTemplate: '/schedules/{id}/export-pdf',
        controller: 'App\Controller\ExportPdfController',
        read: false,
        name: 'export_pdf',
    ),
    new Post(
        uriTemplate: '/schedules/{id}/generate',
        controller: 'App\Controller\GenerateScheduleController',
        input: false,
        read: false,
        name: 'generate_schedule',
    ),
], mercure: true, input: ScheduleInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ScheduleStateProvider::class, processor: ScheduleStateProcessor::class)]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
class ScheduleResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public ScheduleStatus $status;

    #[Groups(['read'])]
    public ?int $score = null;

    #[Groups(['read'])]
    public int $solverSeed = 0;

    #[Groups(['read'])]
    public ?string $snapshotHash = null;

    #[Groups(['read'])]
    public ?string $solverVersion = null;

    #[Groups(['read'])]
    public ?string $constraintVersion = null;

    #[Groups(['read'])]
    public ?string $scoreFormulaVersion = null;

    #[Groups(['read'])]
    public ?int $solverTimeoutSeconds = null;

    #[Groups(['read'])]
    public ?int $solverNbVariables = null;

    #[Groups(['read'])]
    public ?int $solverNbConstraints = null;

    #[Groups(['read'])]
    public ?int $solverNbConflicts = null;

    #[Groups(['read'])]
    public ?int $solverWallTimeMs = null;

    #[Groups(['read'])]
    public ?string $pdfExportStatus = null;

    #[Groups(['read'])]
    public ?string $pdfExportUrl = null;

    public static function fromEntity(Schedule $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->status = $entity->getStatus();
        $dto->score = $entity->getScore();
        $dto->solverSeed = $entity->getSolverSeed();
        $dto->snapshotHash = $entity->getSnapshotHash();
        $dto->solverVersion = $entity->getSolverVersion();
        $dto->constraintVersion = $entity->getConstraintVersion();
        $dto->scoreFormulaVersion = $entity->getScoreFormulaVersion();
        $dto->solverTimeoutSeconds = $entity->getSolverTimeoutSeconds();
        $dto->solverNbVariables = $entity->getSolverNbVariables();
        $dto->solverNbConstraints = $entity->getSolverNbConstraints();
        $dto->solverNbConflicts = $entity->getSolverNbConflicts();
        $dto->solverWallTimeMs = $entity->getSolverWallTimeMs();
        $dto->pdfExportStatus = $entity->getPdfExportStatus();
        $dto->pdfExportUrl = $entity->getPdfExportUrl();

        return $dto;
    }
}
