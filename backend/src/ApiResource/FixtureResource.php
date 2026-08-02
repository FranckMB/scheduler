<?php

declare(strict_types=1);

namespace App\ApiResource;

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
use App\Dto\FixtureInput;
use App\Entity\Fixture;
use App\State\Processor\FixtureStateProcessor;
use App\State\Provider\FixtureStateProvider;
use ArrayObject;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Fixture', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
    // FBI import, one-pass flow (cadrage P1-4, 2026-08-02): the real export is
    // CLUB-WIDE, so both endpoints hang off the fixtures collection — no team
    // in the URL, the tenant context says which club. The openapi context
    // overrides the generated doc (otherwise described as JSON Fixture ops).
    new Post(
        uriTemplate: '/fixtures/import/analyze',
        controller: 'App\Controller\ImportFixturesAnalyzeController',
        openapi: new OpenApiOperation(
            summary: 'Analyze an FBI club export (.xlsx) — dry-run',
            description: 'Multipart upload (field "file"). Parses the club-wide FBI export and returns its division groups resolved against the persisted Division↔team mappings: {divisions[{name, fbiTeamLabel, rowCount, teamId, competitionId}], totalRows, exempted, errors[]}. Writes nothing. 404 no club/membership · 403 non-management member · 409 archived season or socle not validated · 400 missing/invalid file or columns.',
            requestBody: new OpenApiRequestBody(
                content: new ArrayObject([
                    'multipart/form-data' => [
                        'schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']], 'required' => ['file']],
                    ],
                ]),
            ),
        ),
        read: false,
        deserialize: false,
        name: 'analyze_fixtures_import',
    ),
    new Post(
        uriTemplate: '/fixtures/import',
        controller: 'App\Controller\ImportFixturesController',
        openapi: new OpenApiOperation(
            summary: 'Import an FBI club export (.xlsx) with validated mappings',
            description: 'Multipart upload: field "file" + optional field "mappings" (JSON list of {division, fbiTeamLabel?, teamId} — the manager\'s choices from the analyze step; persisted as Competition rows). Creates new fixtures and DIFF-UPDATES known ones (by FBI number per team): a rescheduled date or a HOME↔AWAY switch un-places the match and lands in warnings[]. Report {message, created, updated, unchanged, exempted, errors[], warnings[{type, division, externalRef, message}], unmappedDivisions[]}. 404 no club/membership · 403 non-management member · 409 archived season, socle not validated or concurrent duplicate import · 400 missing/invalid file, columns or mappings.',
            requestBody: new OpenApiRequestBody(
                content: new ArrayObject([
                    'multipart/form-data' => [
                        'schema' => ['type' => 'object', 'properties' => [
                            'file' => ['type' => 'string', 'format' => 'binary'],
                            'mappings' => ['type' => 'string', 'description' => 'JSON list of {division, fbiTeamLabel?, teamId}'],
                        ], 'required' => ['file']],
                    ],
                ]),
            ),
        ),
        read: false,
        deserialize: false,
        name: 'import_fixtures',
    ),
], input: FixtureInput::class, paginationEnabled: true, paginationItemsPerPage: 100, provider: FixtureStateProvider::class, processor: FixtureStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact', 'teamId' => 'exact', 'competitionId' => 'exact', 'homeAway' => 'exact', 'status' => 'exact'])]
class FixtureResource
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
    public string $teamId = '';

    #[Groups(['read'])]
    public string $seasonId = '';

    #[Groups(['read'])]
    public ?string $competitionId = null;

    #[Groups(['read'])]
    public string $matchDate = '';

    #[Groups(['read'])]
    public string $homeAway = '';

    #[Groups(['read'])]
    public string $opponentLabel = '';

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public ?string $kickoffTime = null;

    /** FBI match number (import idempotence key) — null for manual entries. */
    #[Groups(['read'])]
    public ?string $externalRef = null;

    /** Raw FBI « Salle » label, HOME and AWAY — never a Venue reference. */
    #[Groups(['read'])]
    public ?string $fbiVenueLabel = null;

    public static function fromEntity(Fixture $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->seasonId = $entity->getSeasonId();
        $dto->competitionId = $entity->getCompetitionId();
        $dto->matchDate = $entity->getMatchDate()->format('Y-m-d');
        $dto->homeAway = $entity->getHomeAway()->value;
        $dto->opponentLabel = $entity->getOpponentLabel();
        $dto->status = $entity->getStatus()->value;
        $dto->venueId = $entity->getVenueId();
        $dto->kickoffTime = $entity->getKickoffTime()?->format('H:i');
        $dto->externalRef = $entity->getExternalRef();
        $dto->fbiVenueLabel = $entity->getFbiVenueLabel();

        return $dto;
    }
}
