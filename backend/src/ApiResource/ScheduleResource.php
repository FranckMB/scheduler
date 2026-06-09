<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\ScheduleStateProvider;
use App\State\Processor\ScheduleStateProcessor;

use App\Entity\Schedule;

use App\Dto\ScheduleInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "Schedule",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: ScheduleInput::class,
    provider: ScheduleStateProvider::class,
    processor: ScheduleStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class ScheduleResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public \DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public \DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?int $score = null;

    #[Groups(['read'])]
    public int $solverSeed = 0;

    #[Groups(['read'])]
    public ?string $snapshotHash = null;

    #[Groups(['read'])]
    public array $snapshotData = [];

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
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->status = $entity->getStatus();
        $dto->score = $entity->getScore();
        $dto->solverSeed = $entity->getSolverSeed();
        $dto->snapshotHash = $entity->getSnapshotHash();
        $dto->snapshotData = $entity->getSnapshotData();
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
