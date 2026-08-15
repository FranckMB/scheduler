<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintScope;

/**
 * ⚠ Les messages de ce service sont AFFICHÉS TELS QUELS au gestionnaire (récap,
 * « À corriger avant de générer ») : FRANÇAIS, vocabulaire gestionnaire, jamais
 * de clé de config (minAtVenueId…). La carte de traduction frontend a été
 * SUPPRIMÉE (2026-08-05) après une dérive silencieuse — le backend est la
 * source unique du fond ET de la forme.
 */
final class ConstraintValidationService
{
    public function __construct(private readonly ConstraintConfigValidator $configValidator) {}

    /**
     * @return list<string>
     */
    public function validate(Constraint $constraint): array
    {
        // SEC-13 — LA FORME du config est jugée par le MÊME validateur qu'à
        // l'écriture. Ce service n'énonce plus ses propres règles de forme : deux
        // endroits qui disent presque la même chose finissent par diverger (sept
        // écarts mesurés sur le miroir de capacité, revue #341). Il reste ici ce
        // que l'écriture ne peut PAS voir : la cohérence entre champs d'une même
        // contrainte, les contradictions ENTRE contraintes, et l'état du club.
        // Utile malgré le 422 à l'écriture : les fixtures, imports et écritures
        // SQL directes n'y passent pas.
        $errors = $this->configValidator->errors($constraint->getFamily(), $constraint->getConfig());

        // Validate scope + scope_target_id consistency
        $scope = $constraint->getScope();
        $scopeTargetId = $constraint->getScopeTargetId();

        if (ConstraintScope::CLUB !== $scope && null === $scopeTargetId) {
            $errors[] = 'Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.';
        }

        if (ConstraintScope::CLUB === $scope && null !== $scopeTargetId) {
            $errors[] = 'Une contrainte « toutes les équipes » ne doit pas cibler une équipe précise.';
        }

        // Validate config based on family
        $family = $constraint->getFamily();
        $config = $constraint->getConfig();

        switch ($family) {
            case ConstraintFamily::TIME:
                // maxEndTime = "finir avant X h" (l'engine calcule fin = début + durée).
                if (!isset($config['maxStartTime']) && !isset($config['minStartTime']) && !isset($config['maxEndTime'])) {
                    $errors[] = 'Une contrainte d\'horaire doit préciser au moins une heure (début au plus tôt, au plus tard, ou fin).';
                }
                // maxEndTime is honored by the engine ONLY on HARD/LOCK rules (the
                // soft path add_preferred_time_bonus reads only min/maxStartTime).
                // A PREFERRED end-bound would be accepted here yet silently ignored.
                if (isset($config['maxEndTime']) && !\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                    $errors[] = '« Fini avant » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire, sinon elle serait ignorée.';
                }
                break;

            case ConstraintFamily::DAY:
                if (!isset($config['allowedDays']) && !isset($config['forbiddenDays']) && !isset($config['forcedDays'])) {
                    $errors[] = 'Une contrainte de jour doit préciser au moins un jour (autorisé, à éviter ou imposé).';
                }
                break;

            case ConstraintFamily::FACILITY:
                // A FACILITY rule names a VENUE via one of the keys the ENGINE actually
                // reads: forcedVenueId (must-be-at), preferredVenueId (soft/forced when
                // HARD), forbiddenVenueId (avoid), or minAtVenueId (au moins N séances
                // ici — un compte, pas un forçage). A bare `venueId` is honored by NO
                // engine branch, so it is not accepted here.
                if (!isset($config['forcedVenueId']) && !isset($config['forbiddenVenueId']) && !isset($config['preferredVenueId']) && !isset($config['minAtVenueId'])) {
                    $errors[] = 'Une contrainte de gymnase doit désigner un gymnase.';
                }
                // minAtVenueId ("au moins N ici") is honored by the engine ONLY as
                // a per-TEAM, HARD/LOCK count. A CLUB-scoped or PREFERRED one is
                // accepted nowhere in parse_v2_constraints → silently dropped.
                if (isset($config['minAtVenueId'])) {
                    if (!\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                        $errors[] = '« Au moins N séances dans ce gymnase » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire.';
                    }
                    // Une équipe précise, OU un groupe (tag) : l'éclatement CLUB+targetTag
                    // produit des lignes PAR ÉQUIPE qui portent minAtVenueId — l'engine
                    // les lit (retour fondateur 2026-08-05 : le groupe est légitime).
                    // Seul « toutes les équipes » sans tag reste fermé : rien n'éclate,
                    // l'engine jette la ligne en silence.
                    if (ConstraintScope::TEAM !== $scope && !isset($config['targetTag'])) {
                        $errors[] = '« Au moins N séances dans ce gymnase » se pose sur une équipe ou un groupe — pas sur « toutes les équipes ».';
                    }
                }
                break;

            case ConstraintFamily::COACH_AVAILABILITY:
                // SEC-13 : la cible du coach est le SCOPE, plus une clé du config
                // (doublon exact retiré par Version20260807190000). `targetTag`
                // reste une cible légitime — il désigne un GROUPE de coachs.
                if (null === $constraint->getScopeTargetId() && !isset($config['targetTag'])) {
                    $errors[] = 'Une contrainte de disponibilité doit cibler un coach.';
                }
                // Lot C: optional time window (fromTime / untilTime, HH:MM). Absent = whole day.
                $from = $config['fromTime'] ?? null;
                $until = $config['untilTime'] ?? null;
                $bothValid = true;
                foreach (['fromTime' => $from, 'untilTime' => $until] as $key => $value) {
                    if (null === $value) {
                        continue;
                    }
                    if (!\is_string($value) || 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                        $errors[] = \sprintf('L\'heure « %s » doit être au format HH:MM.', 'fromTime' === $key ? 'à partir de' : 'jusqu\'à');
                        $bothValid = false;
                    }
                }
                // Only compare bounds once both parse as HH:MM — otherwise a
                // malformed "25:99" would emit a second, misleading "before" error.
                if ($bothValid && \is_string($from) && \is_string($until) && $from >= $until) {
                    $errors[] = 'L\'heure de début doit précéder l\'heure de fin.';
                }
                break;
        }

        // Validate rule type consistency
        $ruleType = $constraint->getRuleType();
        if ('LOCK' === $ruleType->value && ConstraintFamily::TIME !== $family && ConstraintFamily::DAY !== $family) {
            $errors[] = 'Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.';
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
        // 18:00 vs ADULTE min 18:50) apply to disjoint teams → no conflict. But an
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
                    return 'Contradiction : un même jour est à la fois autorisé et interdit pour la même cible.';
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
                    return 'Contradiction : l\'heure de début au plus tard est AVANT l\'heure de début au plus tôt pour la même cible.';
                }
            }
        }

        return null;
    }
}
