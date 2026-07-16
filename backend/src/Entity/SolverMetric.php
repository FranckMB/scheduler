<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SolverMetricRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Immutable technical telemetry captured for one schedule generation attempt. */
#[ORM\Entity(repositoryClass: SolverMetricRepository::class)]
#[ORM\Table(name: 'solver_metrics')]
#[ORM\Index(name: 'idx_solver_metrics_club_created', columns: ['club_id', 'created_at'])]
#[ORM\Index(name: 'idx_solver_metrics_schedule', columns: ['schedule_id'])]
class SolverMetric implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $scheduleId;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $wallTimeMs;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbVariables;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbConstraints;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbConflicts;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $score;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $solverVersion;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $scheduleId,
        string $clubId,
        string $status,
        ?int $wallTimeMs,
        ?int $nbVariables,
        ?int $nbConstraints,
        ?int $nbConflicts,
        ?int $score,
        ?string $solverVersion,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = self::newUuid();
        $this->scheduleId = $scheduleId;
        $this->clubId = $clubId;
        $this->status = $status;
        $this->wallTimeMs = $wallTimeMs;
        $this->nbVariables = $nbVariables;
        $this->nbConstraints = $nbConstraints;
        $this->nbConflicts = $nbConflicts;
        $this->score = $score;
        $this->solverVersion = $solverVersion;
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
    }

    private static function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getScheduleId(): string
    {
        return $this->scheduleId;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getWallTimeMs(): ?int
    {
        return $this->wallTimeMs;
    }

    public function getNbVariables(): ?int
    {
        return $this->nbVariables;
    }

    public function getNbConstraints(): ?int
    {
        return $this->nbConstraints;
    }

    public function getNbConflicts(): ?int
    {
        return $this->nbConflicts;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getSolverVersion(): ?string
    {
        return $this->solverVersion;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
