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
                // maxEndTime = "finir avant X h" (l'engine calcule fin = début + durée).
                if (!isset($config['maxStartTime']) && !isset($config['minStartTime']) && !isset($config['maxEndTime'])) {
                    $errors[] = 'TIME family requires maxStartTime, minStartTime or maxEndTime in config.';
                }
                // maxEndTime is honored by the engine ONLY on HARD/LOCK rules (the
                // soft path add_preferred_time_bonus reads only min/maxStartTime).
                // A PREFERRED end-bound would be accepted here yet silently ignored.
                if (isset($config['maxEndTime']) && !\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                    $errors[] = 'maxEndTime requires an obligatory (HARD/LOCK) rule — the soft path ignores it.';
                }
                break;

            case ConstraintFamily::DAY:
                if (!isset($config['allowedDays']) && !isset($config['forbiddenDays']) && !isset($config['forcedDays'])) {
                    $errors[] = 'DAY family requires allowedDays, forbiddenDays or forcedDays in config.';
                }
                break;

            case ConstraintFamily::FACILITY:
                // A FACILITY rule names a VENUE via one of the keys the ENGINE actually
                // reads: forcedVenueId (must-be-at), preferredVenueId (soft/forced when
                // HARD), forbiddenVenueId (avoid), or minAtVenueId (au moins N séances
                // ici — un compte, pas un forçage). A bare `venueId` is honored by NO
                // engine branch, so it is not accepted here.
                if (!isset($config['forcedVenueId']) && !isset($config['forbiddenVenueId']) && !isset($config['preferredVenueId']) && !isset($config['minAtVenueId'])) {
                    $errors[] = 'FACILITY family requires forcedVenueId, forbiddenVenueId, preferredVenueId or minAtVenueId in config.';
                }
                // minAtVenueId ("au moins N ici") is honored by the engine ONLY as
                // a per-TEAM, HARD/LOCK count. A CLUB-scoped or PREFERRED one is
                // accepted nowhere in parse_v2_constraints → silently dropped.
                if (isset($config['minAtVenueId'])) {
                    if (!\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                        $errors[] = 'minAtVenueId requires an obligatory (HARD/LOCK) rule — the soft path ignores it.';
                    }
                    if (ConstraintScope::TEAM !== $scope) {
                        $errors[] = 'minAtVenueId requires a team target (scope TEAM) — "au moins N ici" is per-team.';
                    }
                }
                break;

            case ConstraintFamily::COACH_AVAILABILITY:
                if (!isset($config['coachId']) && !isset($config['targetTag'])) {
                    $errors[] = 'COACH_AVAILABILITY family requires coachId or targetTag in config.';
                }
                // Lot C: optional time window (fromTime / untilTime, HH:MM). Absent = whole day.
                $from = $config['fromTime'] ?? null;
                $until = $config['untilTime'] ?? null;
                foreach (['fromTime' => $from, 'untilTime' => $until] as $key => $value) {
                    if (null !== $value && (!\is_string($value) || 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value))) {
                        $errors[] = \sprintf('%s must be a HH:MM time.', $key);
                    }
                }
                if (\is_string($from) && \is_string($until) && $from >= $until) {
                    $errors[] = 'fromTime must be before untilTime.';
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
     * Fail-fast pre-generation check: "au moins N séances dans tel gymnase" is
     * provably impossible when N exceeds the team's weekly sessions. Surface it as
     * an ERROR BEFORE generation rather than a silent INFEASIBLE at solve time.
     * Returns an error string, or null when the rule is fine / not a venue-minimum.
     */
    public function venueMinimumError(Constraint $constraint, ?int $teamSessionsPerWeek): ?string
    {
        $config = $constraint->getConfig();
        if (ConstraintFamily::FACILITY !== $constraint->getFamily() || !isset($config['minAtVenueId'])) {
            return null;
        }

        $count = isset($config['minAtVenueCount']) ? (int) $config['minAtVenueCount'] : 1;
        if (null !== $teamSessionsPerWeek && $count > $teamSessionsPerWeek) {
            return \sprintf('Le minimum de %d séance(s) dans ce gymnase dépasse les %d séance(s)/semaine de l\'équipe — impossible.', $count, $teamSessionsPerWeek);
        }

        return null;
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
        $config1 = $c1->getConfig();
        $config2 = $c2->getConfig();

        // Two rules can only contradict if their TARGET SETS overlap. The targetTag
        // narrows the target: two rules with DIFFERENT non-null tags (e.g. EMB max
        // 18:00 vs SENIOR min 18:50) apply to disjoint teams → no conflict. But an
        // UNTAGGED rule (null tag = the whole club) overlaps every tagged rule, so
        // "overlap" is: same tag, OR at least one side untagged.
        $tag1 = $config1['targetTag'] ?? null;
        $tag2 = $config2['targetTag'] ?? null;
        $targetsOverlap = null === $tag1 || null === $tag2 || $tag1 === $tag2;

        if ($c1->getScopeTargetId() === $c2->getScopeTargetId()
            && $c1->getScope() === $c2->getScope()
            && $targetsOverlap
            && $c1->getFamily() === $c2->getFamily()
            && 'HARD' === $c1->getRuleType()->value
            && 'HARD' === $c2->getRuleType()->value
        ) {
            // Contradictory day constraints — checked BOTH ways (allowed on one side
            // forbidden on the other) so the verdict does not depend on array order.
            if (ConstraintFamily::DAY === $c1->getFamily()) {
                $allowed1 = $config1['allowedDays'] ?? [];
                $forbidden2 = $config2['forbiddenDays'] ?? [];
                $allowed2 = $config2['allowedDays'] ?? [];
                $forbidden1 = $config1['forbiddenDays'] ?? [];

                if (\count(array_intersect($allowed1, $forbidden2)) > 0
                    || \count(array_intersect($allowed2, $forbidden1)) > 0
                ) {
                    return 'Contradictory day constraints: allowed days overlap with forbidden days.';
                }
            }

            // Contradictory time constraints — symmetric: a max on either side below
            // the other side's min is impossible, regardless of iteration order.
            if (ConstraintFamily::TIME === $c1->getFamily()) {
                $max1 = $config1['maxStartTime'] ?? null;
                $min2 = $config2['minStartTime'] ?? null;
                $max2 = $config2['maxStartTime'] ?? null;
                $min1 = $config1['minStartTime'] ?? null;

                if ((null !== $max1 && null !== $min2 && $max1 < $min2)
                    || (null !== $max2 && null !== $min1 && $max2 < $min1)
                ) {
                    return 'Contradictory time constraints: maxStartTime is less than minStartTime.';
                }
            }
        }

        return null;
    }
}
