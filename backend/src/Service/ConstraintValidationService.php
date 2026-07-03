<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintScope;

final class ConstraintValidationService
{
    /**
     * @return list<string>
     */
    public function validate(Constraint $constraint): array
    {
        $errors = [];

        // Validate scope + scope_target_id consistency
        $scope = $constraint->getScope();
        $scopeTargetId = $constraint->getScopeTargetId();

        if (ConstraintScope::CLUB !== $scope && null === $scopeTargetId) {
            $errors[] = \sprintf('Scope %s requires a scope_target_id.', $scope->value);
        }

        if (ConstraintScope::CLUB === $scope && null !== $scopeTargetId) {
            $errors[] = 'Scope CLUB should not have a scope_target_id.';
        }

        // Validate config based on family
        $family = $constraint->getFamily();
        $config = $constraint->getConfig();

        switch ($family) {
            case ConstraintFamily::TIME:
                if (!isset($config['maxStartTime']) && !isset($config['minStartTime'])) {
                    $errors[] = 'TIME family requires maxStartTime or minStartTime in config.';
                }
                break;

            case ConstraintFamily::DAY:
                if (!isset($config['allowedDays']) && !isset($config['forbiddenDays']) && !isset($config['forcedDays'])) {
                    $errors[] = 'DAY family requires allowedDays, forbiddenDays or forcedDays in config.';
                }
                break;

            case ConstraintFamily::FACILITY:
                if (!isset($config['venueId']) && !isset($config['targetTag'])) {
                    $errors[] = 'FACILITY family requires venueId or targetTag in config.';
                }
                break;

            case ConstraintFamily::COACH_AVAILABILITY:
                if (!isset($config['coachId']) && !isset($config['targetTag'])) {
                    $errors[] = 'COACH_AVAILABILITY family requires coachId or targetTag in config.';
                }
                break;

            case ConstraintFamily::FACILITY_CAPACITY:
                if (!isset($config['maxTeams'])) {
                    $errors[] = 'FACILITY_CAPACITY family requires maxTeams in config.';
                }
                break;
        }

        // Validate rule type consistency
        $ruleType = $constraint->getRuleType();
        if ('LOCK' === $ruleType->value && ConstraintFamily::TIME !== $family && ConstraintFamily::DAY !== $family) {
            $errors[] = 'LOCK rule type is only valid for TIME or DAY family.';
        }

        return $errors;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<array{constraint1: Constraint, constraint2: Constraint, reason: string}>
     */
    public function detectConflicts(array $constraints): array
    {
        $conflicts = [];
        $counter = \count($constraints);

        for ($i = 0; $i < $counter; ++$i) {
            for ($j = $i + 1; $j < \count($constraints); ++$j) {
                $c1 = $constraints[$i];
                $c2 = $constraints[$j];

                $conflict = $this->checkConflict($c1, $c2);
                if (null !== $conflict) {
                    $conflicts[] = [
                        'constraint1' => $c1,
                        'constraint2' => $c2,
                        'reason' => $conflict,
                    ];
                }
            }
        }

        return $conflicts;
    }

    private function checkConflict(Constraint $c1, Constraint $c2): ?string
    {
        // Same scope target with contradictory rules
        if ($c1->getScopeTargetId() === $c2->getScopeTargetId()
            && $c1->getScope() === $c2->getScope()
            && $c1->getFamily() === $c2->getFamily()
            && 'HARD' === $c1->getRuleType()->value
            && 'HARD' === $c2->getRuleType()->value
        ) {
            $config1 = $c1->getConfig();
            $config2 = $c2->getConfig();

            // Check for contradictory day constraints
            if (ConstraintFamily::DAY === $c1->getFamily()) {
                $allowed1 = $config1['allowedDays'] ?? [];
                $forbidden2 = $config2['forbiddenDays'] ?? [];

                if (\count(array_intersect($allowed1, $forbidden2)) > 0) {
                    return 'Contradictory day constraints: allowed days overlap with forbidden days.';
                }
            }

            // Check for contradictory time constraints
            if (ConstraintFamily::TIME === $c1->getFamily()) {
                $max1 = $config1['maxStartTime'] ?? null;
                $min2 = $config2['minStartTime'] ?? null;

                if (null !== $max1 && null !== $min2 && $max1 < $min2) {
                    return 'Contradictory time constraints: maxStartTime is less than minStartTime.';
                }
            }
        }

        return null;
    }
}
