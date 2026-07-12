<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ConstraintResource;
use App\Dto\ConstraintInput;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;

/**
 * @extends AbstractStateProcessor<Constraint, ConstraintInput, ConstraintResource>
 */
class ConstraintStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Constraint::class;
    }

    /**
     * @param ConstraintInput $input
     */
    protected function createEntityFromInput(object $input): Constraint
    {
        $entity = new Constraint;
        $entity->setName($input->name ?? '');
        $entity->setDescription($input->description);
        $entity->setScope($this->parseScope($input->scope));
        $entity->setScopeTargetId($input->scopeTargetId);
        $entity->setFamily($this->parseFamily($input->family));
        $entity->setRuleType($this->parseRuleType($input->ruleType));
        $entity->setConfig($input->config ?? []);
        $entity->setCreatedBy($input->createdBy);
        $entity->setSource($input->source);
        $entity->setSourceOccurrenceId($input->sourceOccurrenceId);
        $entity->setCalendarEntryId($input->calendarEntryId);
        $entity->setIsActive($input->isActive ?? true);
        $entity->setSortOrder($input->sortOrder ?? 0);

        return $entity;
    }

    /**
     * @param Constraint      $entity
     * @param ConstraintInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->description) {
            $entity->setDescription($input->description);
        }
        if (null !== $input->scope) {
            $entity->setScope($this->parseScope($input->scope));
        }
        if (null !== $input->scopeTargetId) {
            $entity->setScopeTargetId($input->scopeTargetId);
        }
        // Invariant: a CLUB-scoped constraint has NO target. A PUT that widens a
        // TEAM/COACH rule to CLUB sends scopeTargetId=null, but a null field means
        // "leave unchanged" above — so clear it explicitly, else a stale target id
        // survives with scope=CLUB (mis-read by expandClosedVenues, ScheduleConstraintBuilder).
        if (ConstraintScope::CLUB === $entity->getScope()) {
            $entity->setScopeTargetId(null);
        }
        if (null !== $input->family) {
            $entity->setFamily($this->parseFamily($input->family));
        }
        if (null !== $input->ruleType) {
            $entity->setRuleType($this->parseRuleType($input->ruleType));
        }
        if (null !== $input->config) {
            $entity->setConfig($input->config);
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->sourceOccurrenceId) {
            $entity->setSourceOccurrenceId($input->sourceOccurrenceId);
        }
        if (null !== $input->calendarEntryId) {
            $entity->setCalendarEntryId($input->calendarEntryId);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->sortOrder) {
            $entity->setSortOrder($input->sortOrder);
        }
    }

    /**
     * @param Constraint $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintResource
    {
        return ConstraintResource::fromEntity($entity);
    }

    /**
     * @param Constraint $entity
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        // A period may have disabled this permanent constraint for its window; those
        // toggles are keyed on constraintId and would orphan on a bare delete.
        foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['constraintId' => $entity->getId()]) as $override) {
            $this->entityManager->remove($override);
        }
    }

    private function parseScope(?string $value): ConstraintScope
    {
        return ConstraintScope::tryFrom($value ?? '') ?? ConstraintScope::CLUB;
    }

    private function parseFamily(?string $value): ConstraintFamily
    {
        return ConstraintFamily::tryFrom($value ?? '') ?? ConstraintFamily::TIME;
    }

    private function parseRuleType(?string $value): ConstraintRuleType
    {
        return ConstraintRuleType::tryFrom($value ?? '') ?? ConstraintRuleType::HARD;
    }
}
