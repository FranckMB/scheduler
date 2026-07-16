<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Dto\SchedulePlanInput;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\State\Processor\SchedulePlanStateProcessor;
use App\State\Provider\SchedulePlanStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// ⚠️ Le DOCBLOCK ci-dessous est PUBLIÉ : API Platform le recopie verbatim dans la
// description OpenAPI, et /api/docs est en PUBLIC_ACCESS (security.yaml). N'y écrire
// que ce qu'un consommateur externe doit lire — jamais de raisonnement interne, de
// chemin de fichier, ni d'état de migration. Ces commentaires `//`, eux, ne sont pas
// publiés. Le raisonnement vit dans docs/architecture/adr-0002-pattern-plan.md.
/**
 * Le Plan — le conteneur nommé des versions d'une saison ou d'une période. Le plan
 * de saison et sa version choisie sont le calendrier de base de la saison.
 *
 * Un plan est créé et supprimé par le serveur, et sa version choisie est désignée
 * en validant cette version. Seul son nom est éditable.
 */
// The ?calendarEntryId / ?type query filters are implemented by the custom
// provider (SchedulePlanStateProvider::applyRequestFilters) — a Doctrine
// #[ApiFilter] would be inert here since the custom provider bypasses the
// Doctrine extensions.
#[ApiResource(shortName: 'SchedulePlan', operations: [
    new GetCollection,
    new Get,
    new Put,
], input: SchedulePlanInput::class, paginationEnabled: false, provider: SchedulePlanStateProvider::class, processor: SchedulePlanStateProcessor::class)]
class SchedulePlanResource
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
    public string $seasonId = '';

    #[Groups(['read'])]
    public SchedulePlanType $type;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public DateTimeImmutable $startDate;

    #[Groups(['read'])]
    public DateTimeImmutable $endDate;

    #[Groups(['read'])]
    public ?string $calendarEntryId = null;

    #[Groups(['read'])]
    public ?string $chosenScheduleId = null;

    public static function fromEntity(SchedulePlan $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->seasonId = $entity->getSeasonId();
        $dto->type = $entity->getType();
        $dto->name = $entity->getName();
        $dto->startDate = $entity->getStartDate();
        $dto->endDate = $entity->getEndDate();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->chosenScheduleId = $entity->getChosenScheduleId();

        return $dto;
    }
}
