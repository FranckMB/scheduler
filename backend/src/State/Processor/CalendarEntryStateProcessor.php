<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CalendarEntryResource;
use App\Dto\CalendarEntryInput;
use App\Entity\CalendarEntry;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<CalendarEntry, CalendarEntryInput, CalendarEntryResource>
 *
 * Palier A: writing a CalendarEntry (or its dated Constraint via
 * Constraint.calendarEntryId) never touches the generation payload — dated
 * constraints are excluded from ConstraintRepository::findPermanentByClubSeason,
 * so the cached snapshot (club.{id}.schedule_input) needs no invalidation here.
 * Palier B (overlay generation) will change that and must invalidate the cache.
 */
class CalendarEntryStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CalendarEntry::class;
    }

    /**
     * @param CalendarEntryInput $input
     */
    protected function createEntityFromInput(object $input): CalendarEntry
    {
        $entity = new CalendarEntry;
        $entity->setKind($this->parseKind($input->kind));
        $entity->setTitle($input->title ?? '');
        $entity->setStartDate($this->parseDate($input->startDate));
        $entity->setEndDate($this->parseDate($input->endDate));
        $entity->setIsDisruptive($input->isDisruptive ?? false);
        $entity->setPeriodType($this->parsePeriodType($input->periodType));
        $entity->setSchoolHolidayId($input->schoolHolidayId);
        $entity->setStatus($this->parseStatus($input->status));
        $entity->setCreatedBy($input->createdBy);

        return $entity;
    }

    /**
     * @param CalendarEntry      $entity
     * @param CalendarEntryInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->kind) {
            $entity->setKind($this->parseKind($input->kind));
        }
        if (null !== $input->title) {
            $entity->setTitle($input->title);
        }
        if (null !== $input->startDate) {
            $entity->setStartDate($this->parseDate($input->startDate));
        }
        if (null !== $input->endDate) {
            $entity->setEndDate($this->parseDate($input->endDate));
        }
        if (null !== $input->isDisruptive) {
            $entity->setIsDisruptive($input->isDisruptive);
        }
        if (null !== $input->periodType) {
            $entity->setPeriodType($this->parsePeriodType($input->periodType));
        }
        if (null !== $input->schoolHolidayId) {
            $entity->setSchoolHolidayId($input->schoolHolidayId);
        }
        if (null !== $input->status) {
            $entity->setStatus($this->parseStatus($input->status));
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
    }

    /**
     * @param CalendarEntry $entity
     */
    protected function mapEntityToOutput(object $entity): CalendarEntryResource
    {
        return CalendarEntryResource::fromEntity($entity);
    }

    private function parseKind(?string $value): CalendarEntryKind
    {
        return CalendarEntryKind::tryFrom($value ?? '') ?? CalendarEntryKind::EVENT;
    }

    private function parsePeriodType(?string $value): ?CalendarEntryPeriodType
    {
        if (null === $value) {
            return null;
        }

        return CalendarEntryPeriodType::tryFrom($value);
    }

    private function parseStatus(?string $value): CalendarEntryStatus
    {
        return CalendarEntryStatus::tryFrom($value ?? '') ?? CalendarEntryStatus::ACTIVE;
    }

    private function parseDate(?string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value ?? 'now');
    }
}
