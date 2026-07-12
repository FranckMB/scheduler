<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScheduleStatus;
use App\Repository\ScheduleRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduleRepository::class)]
#[ORM\Table(name: 'schedule')]
#[ORM\Index(name: 'idx_schedule_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_schedule_status', columns: ['status'])]
// Non-unique: a period carries SEVERAL overlay versions (V1, V2…) — the old
// uniq_schedule_calendar_entry was dropped by Version20260711120000
// (planning-versions). The entity mapping had drifted (still declared UNIQUE),
// so migration-diff would regenerate the unique index and re-break V2 overlays.
#[ORM\Index(name: 'idx_schedule_calendar_entry', columns: ['calendar_entry_id'], options: ['where' => '(calendar_entry_id IS NOT NULL)'])]
// ADR-0002: version numbers are unique within a SchedulePlan (V1, V2…). Partial
// so the many rows still unlinked during the additive transition don't collide.
#[ORM\UniqueConstraint(name: 'uniq_schedule_plan_version', columns: ['schedule_plan_id', 'version_number'], options: ['where' => '(schedule_plan_id IS NOT NULL AND version_number IS NOT NULL)'])]
#[ORM\HasLifecycleCallbacks]
class Schedule implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /**
     * When set, this schedule is the OVERLAY of a CalendarEntry period (palier B):
     * a bounded secondary plan, never the season baseline. null = a season plan
     * (base / work-loop). Inverse of CalendarEntry.overlayScheduleId.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $calendarEntryId = null;

    /**
     * ADR-0002: the SchedulePlan this schedule is a VERSION of. Nullable during
     * the additive transition (Lot A) — the backfill + SchedulePlanProvisioner
     * fill it; made NOT NULL in Lot D once every schedule is linked.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

    /**
     * ADR-0002: this schedule's position within its SchedulePlan (V1, V2…),
     * stored (not derived). Nullable during the additive transition; assigned by
     * the provisioner as MAX(versionNumber of the plan) + 1.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $versionNumber = null;

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(length: 30, enumType: ScheduleStatus::class)]
    private ScheduleStatus $status;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $score = null;

    #[ORM\Column(type: 'integer')]
    private int $solverSeed = 42;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $snapshotHash = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $snapshotData = [];

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $solverVersion = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $constraintVersion = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $scoreFormulaVersion = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverTimeoutSeconds = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverNbVariables = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverNbConstraints = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverNbConflicts = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverWallTimeMs = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $pdfExportStatus = null;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $pdfExportUrl = null;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $pngExportUrl = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getCalendarEntryId(): ?string
    {
        return $this->calendarEntryId;
    }

    public function setCalendarEntryId(?string $calendarEntryId): self
    {
        $this->calendarEntryId = $calendarEntryId;

        return $this;
    }

    public function getSchedulePlanId(): ?string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(?string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getVersionNumber(): ?int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(?int $versionNumber): self
    {
        $this->versionNumber = $versionNumber;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): ScheduleStatus
    {
        return $this->status;
    }

    public function setStatus(ScheduleStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getSolverSeed(): int
    {
        return $this->solverSeed;
    }

    public function setSolverSeed(int $solverSeed): self
    {
        $this->solverSeed = $solverSeed;

        return $this;
    }

    public function getSnapshotHash(): ?string
    {
        return $this->snapshotHash;
    }

    public function setSnapshotHash(?string $snapshotHash): self
    {
        $this->snapshotHash = $snapshotHash;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSnapshotData(): array
    {
        return $this->snapshotData;
    }

    /** @param array<string, mixed> $snapshotData */
    public function setSnapshotData(array $snapshotData): self
    {
        $this->snapshotData = $snapshotData;

        return $this;
    }

    public function getSolverVersion(): ?string
    {
        return $this->solverVersion;
    }

    public function setSolverVersion(?string $solverVersion): self
    {
        $this->solverVersion = $solverVersion;

        return $this;
    }

    public function getConstraintVersion(): ?string
    {
        return $this->constraintVersion;
    }

    public function setConstraintVersion(?string $constraintVersion): self
    {
        $this->constraintVersion = $constraintVersion;

        return $this;
    }

    public function getScoreFormulaVersion(): ?string
    {
        return $this->scoreFormulaVersion;
    }

    public function setScoreFormulaVersion(?string $scoreFormulaVersion): self
    {
        $this->scoreFormulaVersion = $scoreFormulaVersion;

        return $this;
    }

    public function getSolverTimeoutSeconds(): ?int
    {
        return $this->solverTimeoutSeconds;
    }

    public function setSolverTimeoutSeconds(?int $solverTimeoutSeconds): self
    {
        $this->solverTimeoutSeconds = $solverTimeoutSeconds;

        return $this;
    }

    public function getSolverNbVariables(): ?int
    {
        return $this->solverNbVariables;
    }

    public function setSolverNbVariables(?int $solverNbVariables): self
    {
        $this->solverNbVariables = $solverNbVariables;

        return $this;
    }

    public function getSolverNbConstraints(): ?int
    {
        return $this->solverNbConstraints;
    }

    public function setSolverNbConstraints(?int $solverNbConstraints): self
    {
        $this->solverNbConstraints = $solverNbConstraints;

        return $this;
    }

    public function getSolverNbConflicts(): ?int
    {
        return $this->solverNbConflicts;
    }

    public function setSolverNbConflicts(?int $solverNbConflicts): self
    {
        $this->solverNbConflicts = $solverNbConflicts;

        return $this;
    }

    public function getSolverWallTimeMs(): ?int
    {
        return $this->solverWallTimeMs;
    }

    public function setSolverWallTimeMs(?int $solverWallTimeMs): self
    {
        $this->solverWallTimeMs = $solverWallTimeMs;

        return $this;
    }

    public function getPdfExportStatus(): ?string
    {
        return $this->pdfExportStatus;
    }

    public function setPdfExportStatus(?string $pdfExportStatus): self
    {
        $this->pdfExportStatus = $pdfExportStatus;

        return $this;
    }

    public function getPdfExportUrl(): ?string
    {
        return $this->pdfExportUrl;
    }

    public function setPdfExportUrl(?string $pdfExportUrl): self
    {
        $this->pdfExportUrl = $pdfExportUrl;

        return $this;
    }

    public function getPngExportUrl(): ?string
    {
        return $this->pngExportUrl;
    }

    public function setPngExportUrl(?string $pngExportUrl): self
    {
        $this->pngExportUrl = $pngExportUrl;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
