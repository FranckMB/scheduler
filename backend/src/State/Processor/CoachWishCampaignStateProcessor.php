<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\CoachWishCampaignResource;
use App\Dto\CoachWishCampaignInput;
use App\Entity\CalendarEntry;
use App\Entity\CoachWishCampaign;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Service\CoachWishCampaignPresenter;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Campagne de collecte (feature #10, lot C2). Écriture MANAGEMENT-ONLY (SEC-07).
 *
 * POST crée la campagne (une par période, mère holiday) et synchronise les tokens des
 * coachs des équipes retenues. PUT modifie deadline/weeks/teamIds (jamais calendarEntryId)
 * et re-synchronise (complète les tokens manquants, n'en supprime aucun).
 *
 * @extends AbstractStateProcessor<CoachWishCampaign, CoachWishCampaignInput, CoachWishCampaignResource>
 */
class CoachWishCampaignStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly CoachWishCampaignTokenSync $tokenSync,
        private readonly CoachWishCampaignPresenter $presenter,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return CoachWishCampaign::class;
    }

    protected function requiresManagementRole(): bool
    {
        return true; // SEC-07
    }

    /**
     * @param CoachWishCampaignInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(
            fn (): object => parent::processPost($input, $clubId, $seasonId),
        );
    }

    /**
     * @param array<string, mixed>   $uriVariables
     * @param CoachWishCampaignInput $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(
            fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId),
        );
    }

    /**
     * Les tokens doivent exister AVANT la projection de la réponse (ils n'existent
     * pas encore au moment du persist). Le faire dans ce hook — appelé par le
     * parent juste avant `mapEntityToOutput` — laisse le presenter tourner UNE
     * SEULE fois (P4-27 : POST/PUT le lançaient deux fois, la 1re projection
     * étant jetée parce qu'elle précédait la sync).
     */
    protected function afterPersist(object $entity): void
    {
        if ($entity instanceof CoachWishCampaign) {
            $this->tokenSync->sync($entity);
        }
    }

    /**
     * @param CoachWishCampaignInput $input
     */
    protected function createEntityFromInput(object $input): CoachWishCampaign
    {
        $this->assertValidAnchor($input);

        // Une seule campagne par période — l'index unique remonterait sinon en 500 ; 422 propre.
        if (null !== $this->entityManager->getRepository(CoachWishCampaign::class)->findOneBy(['calendarEntryId' => $input->calendarEntryId])) {
            throw new ValidationException('Une collecte existe déjà pour cette période — modifiez-la.');
        }

        $entity = new CoachWishCampaign;
        $entity->setCalendarEntryId((string) $input->calendarEntryId);
        $this->applyEditableFields($entity, $input);

        return $entity;
    }

    /**
     * @param CoachWishCampaign      $entity
     * @param CoachWishCampaignInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // calendarEntryId identifie la campagne — jamais remappé.
        $this->assertValidAnchor($input, $entity->getCalendarEntryId());
        $this->applyEditableFields($entity, $input);
        $entity->touch();
    }

    /**
     * @param CoachWishCampaign $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishCampaignResource
    {
        return $this->presenter->toResource($entity);
    }

    private function applyEditableFields(CoachWishCampaign $entity, CoachWishCampaignInput $input): void
    {
        $entity->setDeadline(new DateTimeImmutable((string) $input->deadline . ' 00:00:00'));
        $entity->setWeeks(array_map('strval', $input->weeks));
        $entity->setTeamIds(array_map('strval', $input->teamIds));
    }

    /**
     * La campagne s'ancre à l'entrée MÈRE des vacances ; ses semaines sont des lundis de la
     * fenêtre, ses équipes existent. Tenant scoppe déjà l'entrée/les équipes au club.
     * `$knownEntryId` : au PUT, l'ancre est celle de la ligne, jamais celle du corps.
     */
    private function assertValidAnchor(CoachWishCampaignInput $input, ?string $knownEntryId = null): void
    {
        $entryId = $knownEntryId ?? $input->calendarEntryId;
        $entry = null === $entryId ? null : $this->entityManager->getRepository(CalendarEntry::class)->find($entryId);
        if (!$entry instanceof CalendarEntry) {
            throw new ValidationException('Période introuvable.');
        }
        if (CalendarEntryKind::PERIOD !== $entry->getKind() || CalendarEntryPeriodType::HOLIDAY !== $entry->getPeriodType()) {
            throw new ValidationException('La collecte ne concerne que les périodes de vacances.');
        }
        if (null !== $entry->getParentEntryId()) {
            throw new ValidationException('Adressez la collecte à la période mère, pas à une semaine isolée.');
        }

        foreach ($input->weeks as $week) {
            $weekStart = new DateTimeImmutable((string) $week . ' 00:00:00');
            if ('1' !== $weekStart->format('N')) {
                throw new ValidationException('Chaque semaine doit commencer un lundi.');
            }
            // Fenêtre : la semaine (lundi→dimanche) doit intersecter la période, date à date.
            $weekEnd = $weekStart->modify('+6 days');
            if ($weekStart > $entry->getEndDate() || $weekEnd < $entry->getStartDate()) {
                throw new ValidationException('Une semaine choisie ne recoupe pas la période de vacances.');
            }
        }

        foreach ($input->teamIds as $teamId) {
            if (null === $this->entityManager->getRepository(Team::class)->find($teamId)) {
                throw new ValidationException('Équipe introuvable.');
            }
        }
    }
}
