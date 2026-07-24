<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\CoachWishResource;
use App\Dto\CoachWishInput;
use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use DateTimeImmutable;

/**
 * Doléance coach (feature #10, lot C1). Écriture MANAGEMENT-ONLY (SEC-07) : une doléance
 * pilote la négociation du plan de vacances, pas un coach lambda.
 *
 * Une doléance est un SOUHAIT, jamais une contrainte : aucun effet solveur, aucun
 * pré-remplissage. Son ancre (entrée MÈRE des vacances + lundi de la semaine) identifie la
 * ligne ; le PUT ne la remappe jamais.
 *
 * @extends AbstractStateProcessor<CoachWish, CoachWishInput, CoachWishResource>
 */
class CoachWishStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CoachWish::class;
    }

    protected function requiresManagementRole(): bool
    {
        return true; // SEC-07
    }

    /**
     * @param CoachWishInput $input
     */
    protected function createEntityFromInput(object $input): CoachWish
    {
        $this->assertValidAnchor($input);

        // Une seule doléance par (période, équipe, semaine) — l'index unique remonterait
        // sinon en 500 sur un double-submit ; on rend un 422 propre (l'édition passe par PUT).
        if (null !== $this->entityManager->getRepository(CoachWish::class)->findOneBy([
            'calendarEntryId' => $input->calendarEntryId,
            'teamId' => $input->teamId,
            'weekStart' => $this->parseWeekStart((string) $input->weekStart),
        ])) {
            throw new ValidationException('Une doléance existe déjà pour cette équipe et cette semaine — modifiez-la.');
        }

        $entity = new CoachWish;
        $entity->setCalendarEntryId((string) $input->calendarEntryId);
        $entity->setWeekStart($this->parseWeekStart((string) $input->weekStart));
        $entity->setTeamId((string) $input->teamId);
        $this->applyEditableFields($entity, $input);

        return $entity;
    }

    /**
     * @param CoachWish      $entity
     * @param CoachWishInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // calendarEntryId + teamId + weekStart identifient la ligne — jamais remappés.
        $this->applyEditableFields($entity, $input);
    }

    /**
     * @param CoachWish $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishResource
    {
        return CoachWishResource::fromEntity($entity);
    }

    private function applyEditableFields(CoachWish $entity, CoachWishInput $input): void
    {
        $entity->setCoachId($input->coachId);
        $entity->setSlotsWanted($input->slotsWanted ?? 0);
        $entity->setUnavailableDays(array_map('intval', $input->unavailableDays));
        $entity->setComment(null === $input->comment || '' === trim($input->comment) ? null : $input->comment);
        $entity->setDone($input->done);
    }

    /**
     * La doléance s'ancre à l'entrée MÈRE des vacances + un lundi de sa fenêtre. Tenant
     * scoppe déjà l'entrée au club (tenant_filter) : introuvable = 422.
     */
    private function assertValidAnchor(CoachWishInput $input): void
    {
        $entry = null === $input->calendarEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($input->calendarEntryId);
        if (!$entry instanceof CalendarEntry) {
            throw new ValidationException('Période introuvable.');
        }
        if (CalendarEntryKind::PERIOD !== $entry->getKind() || CalendarEntryPeriodType::HOLIDAY !== $entry->getPeriodType()) {
            throw new ValidationException('Les doléances ne concernent que les périodes de vacances.');
        }
        if (null !== $entry->getParentEntryId()) {
            throw new ValidationException('Adressez la doléance à la période mère, pas à une semaine isolée.');
        }

        $weekStart = $this->parseWeekStart((string) $input->weekStart);
        if ('1' !== $weekStart->format('N')) {
            throw new ValidationException('La semaine doit commencer un lundi.');
        }
        // Fenêtre élargie : la semaine (lundi→dimanche) doit INTERSECTER la période — c'est
        // l'ensemble que `weeksCovering` produit côté front (une semaine peut déborder avant
        // le début des vacances tout en les touchant).
        $weekEnd = $weekStart->modify('+6 days');
        if ($weekStart > $entry->getEndDate() || $weekEnd < $entry->getStartDate()) {
            throw new ValidationException('Cette semaine ne recoupe pas la période de vacances.');
        }

        if (null === $this->entityManager->getRepository(Team::class)->find($input->teamId)) {
            throw new ValidationException('Équipe introuvable.');
        }
        if (null === $this->entityManager->getRepository(Coach::class)->find($input->coachId)) {
            throw new ValidationException('Coach introuvable.');
        }
    }

    private function parseWeekStart(string $value): DateTimeImmutable
    {
        // Ancré à midi UTC : une DATE pure, indépendante du fuseau — pas de dérive de jour.
        return new DateTimeImmutable($value . ' 12:00:00');
    }
}
