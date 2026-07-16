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
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody as OpenApiRequestBody;
use App\Dto\TeamInput;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\State\Processor\TeamStateProcessor;
use App\State\Provider\TeamStateProvider;
use ArrayObject;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Team', operations: [
    new GetCollection,
    new Get,
    new Post(validationContext: ['groups' => ['Default', 'create']]),
    new Put,
    new Delete,
    // FBI fixtures import (module matchs PR-4): multipart upload of one FBI
    // export for ONE team — same declaration pattern as clubs/{id}/import-teams.
    // The openapi context overrides the generated doc, which would otherwise
    // describe this as a plain "create Team" JSON operation.
    new Post(
        uriTemplate: '/teams/{id}/fixtures/import',
        controller: 'App\Controller\ImportFixturesController',
        read: false,
        deserialize: false,
        name: 'import_fixtures',
        openapi: new OpenApiOperation(
            summary: 'Import an FBI fixtures export (.xlsx) for this team',
            description: 'Multipart upload (field "file"). Per-row report {message, created, skipped, errors[]}. Idempotent by FBI match number (re-upload skips). 404 unknown/foreign team · 403 non-management member · 409 archived season or concurrent duplicate import · 400 missing/invalid file or columns.',
            requestBody: new OpenApiRequestBody(
                content: new ArrayObject([
                    'multipart/form-data' => [
                        'schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']], 'required' => ['file']],
                    ],
                ]),
            ),
        ),
    ),
], input: TeamInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamStateProvider::class, processor: TeamStateProcessor::class)]
// Honored by TeamStateProvider::applyRequestFilters (the custom provider bypasses
// API Platform's built-in Doctrine filters) — documented AND functional (BCK-05).
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
class TeamResource
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
    public string $sportCategoryId = '';

    #[Groups(['read'])]
    public int $priorityTierId = 0;

    #[Groups(['read'])]
    public int $tierOrder = 0;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public ?Gender $gender = null;

    #[Groups(['read'])]
    public ?TeamLevel $level = null;

    /**
     * Cette équipe joue-t-elle déjà en compétition (≥1 match non-UNPLACED, extérieur
     * compris) ? Alors elle ne peut plus être supprimée ni changer de `level` — elle
     * est inscrite ainsi auprès de la fédération. Son tier, ses créneaux et son nom
     * restent libres.
     *
     * Exposé pour que l'UI grise ces deux gestes sans re-dériver la règle de son côté :
     * un second endroit qui répond « engagée ? » finirait par répondre autre chose que
     * le serveur, et l'écran offrirait un geste toujours refusé. Rempli en lot par
     * TeamStateProvider ; false sur le chemin nu de fromEntity.
     */
    #[Groups(['read'])]
    public bool $isEngaged = false;

    /**
     * Combien de matchs pendent à cette équipe — engagés ou non. Supprimer l'équipe les
     * emporte (`EntityCascadeDeleter::purgeChildrenOfTeam`), donc la confirmation doit
     * les annoncer : une équipe supprimable est une équipe dont aucun match n'est encore
     * placé, c'est-à-dire typiquement un import FBI entier. Rempli en lot par
     * TeamStateProvider ; 0 sur le chemin nu de fromEntity.
     */
    #[Groups(['read'])]
    public int $fixtureCount = 0;

    #[Groups(['read'])]
    public int $sessionsPerWeek = 0;

    #[Groups(['read'])]
    public ?int $minSessionsOverride = null;

    #[Groups(['read'])]
    public ?int $matchDay = null;

    #[Groups(['read'])]
    public bool $allowMultipleSessionsPerDay = false;

    #[Groups(['read'])]
    public ?string $forcedVenueId = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public ?string $parentTeamId = null;

    public static function fromEntity(Team $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->sportCategoryId = $entity->getSportCategoryId();
        $dto->priorityTierId = $entity->getPriorityTierId();
        $dto->tierOrder = $entity->getTierOrder();
        $dto->name = $entity->getName();
        $dto->gender = $entity->getGender();
        $dto->level = $entity->getLevel();
        $dto->sessionsPerWeek = $entity->getSessionsPerWeek();
        $dto->minSessionsOverride = $entity->getMinSessionsOverride();
        $dto->matchDay = $entity->getMatchDay();
        $dto->allowMultipleSessionsPerDay = $entity->getAllowMultipleSessionsPerDay();
        $dto->forcedVenueId = $entity->getForcedVenueId();
        $dto->isActive = $entity->getIsActive();
        $dto->parentTeamId = $entity->getParentTeamId();

        return $dto;
    }
}
