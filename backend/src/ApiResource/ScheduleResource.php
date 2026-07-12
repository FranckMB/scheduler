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
        uriTemplate: '/schedules/{id}/export-xlsx',
        controller: 'App\Controller\ExportXlsxController',
        read: false,
        name: 'export_xlsx',
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

    /** Non-null → this is a period overlay (palier B), not a season plan. */
    #[Groups(['read'])]
    public ?string $calendarEntryId = null;

    /** ADR-0002: the SchedulePlan this schedule is a version of (null pre-backfill). */
    #[Groups(['read'])]
    public ?string $schedulePlanId = null;

    /** ADR-0002: this schedule's version number within its plan (V1, V2…). */
    #[Groups(['read'])]
    public ?int $versionNumber = null;

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

    #[Groups(['read'])]
    public ?string $pngExportUrl = null;

    /**
     * Number of teams in the frozen solve input (planning-versions divergence
     * banner: "generated with N teams — the structure has changed since").
     * Read-only; null until a generation froze a snapshot.
     */
    #[Groups(['read'])]
    public ?int $generatedTeamCount = null;

    /**
     * planning-versions D3: does this version carry a restorable structure photo
     * (ScheduleStructureSnapshot, D2)? Only then can "Charger cette version"
     * succeed — a plan generated before D2 has a solver payload (so
     * generatedTeamCount is set) but no photo, and must not offer the action.
     * Set by ScheduleStateProvider (batched); false on the bare fromEntity path.
     */
    #[Groups(['read'])]
    public bool $hasStructurePhoto = false;

    /**
     * planning-versions: is THIS the version whose structure is the season's
     * currently loaded context (★)? Set on every COMPLETED season plan and
     * re-pointed by "Charger cette version". The ★ tracks the loaded context,
     * not the version being viewed. Set by ScheduleStateProvider (batched).
     */
    #[Groups(['read'])]
    public bool $isLiveContext = false;

    public static function fromEntity(Schedule $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->status = $entity->getStatus();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->versionNumber = $entity->getVersionNumber();
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
        $dto->pngExportUrl = $entity->getPngExportUrl();
        $snapshotTeams = $entity->getSnapshotData()['teams'] ?? null;
        $dto->generatedTeamCount = \is_array($snapshotTeams) ? \count($snapshotTeams) : null;

        return $dto;
    }
}
