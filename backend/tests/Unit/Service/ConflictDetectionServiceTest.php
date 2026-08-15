<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ConstraintConfigValidator;
use App\Service\ConstraintValidationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConflictDetectionServiceTest extends TestCase
{
    private ConstraintValidationService $service;

    public function testNoConflictsWithCompatibleConstraints(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['forbiddenDays' => [1, 2]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [3, 4]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testDetectsHardHardDayConflict(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['allowedDays' => [1, 2, 3]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(1, $conflicts);
        self::assertSame($c1, $conflicts[0]['constraint1']);
        self::assertSame($c2, $conflicts[0]['constraint2']);
        self::assertSame('Contradiction : un même jour est à la fois autorisé et interdit pour la même cible.', $conflicts[0]['reason']);
    }

    public function testDetectsHardHardTimeConflict(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '19:00']);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(1, $conflicts);
        self::assertSame('Contradiction : l\'heure de début au plus tard est AVANT l\'heure de début au plus tôt pour la même cible.', $conflicts[0]['reason']);
    }

    public function testNoConflictWithDifferentScopeTargetIds(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['allowedDays' => [1]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-2');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testNoConflictWithDifferentFamilies(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testNoConflictWithNonHardRuleType(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::PREFERRED);
        $c1->setConfig(['allowedDays' => [1]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testNoConflictBetweenDifferentTargetTags(): void
    {
        // EMB max 18:00 vs SENIOR min 18:50 are both CLUB-scoped but hit disjoint
        // teams → not a conflict.
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00', 'targetTag' => 'EMB']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTag' => 'SENIOR']);

        self::assertCount(0, $this->service->detectConflicts([$c1, $c2]));
    }

    public function testDetectsConflictBetweenUntaggedAndTaggedRule(): void
    {
        // An untagged CLUB rule (whole club) overlaps a tagged rule on that tag's
        // teams → a genuine contradiction must be reported.
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00']); // no targetTag → all teams

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTag' => 'SENIOR']);

        self::assertCount(1, $this->service->detectConflicts([$c1, $c2]));
    }

    /**
     * P2-29 D14 — recouvrement CONSERVATIF sur cibles multiples : deux règles qui PARTAGENT
     * un tag se recouvrent (intersection non vide possible) → conflit signalé.
     */
    public function testDetectsConflictBetweenTargetSetsThatShareATag(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00', 'targetTags' => ['ADULTE', 'COMPETITION']]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTags' => ['COMPETITION']]);

        self::assertCount(1, $this->service->detectConflicts([$c1, $c2]), 'COMPETITION est commun aux deux cibles → recouvrement');
    }

    /**
     * P2-29 D14 — la SEULE preuve statique de non-recouvrement : des cibles de tags DISJOINTS,
     * sans exclusion. Deux tags qui ne se croisent pas ne peuvent pas se contredire.
     */
    public function testNoConflictBetweenDisjointMultiTagTargets(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00', 'targetTags' => ['ADULTE']]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTags' => ['BABY']]);

        self::assertCount(0, $this->service->detectConflicts([$c1, $c2]));
    }

    /**
     * P2-29 D14, CORRIGÉ le 2026-08-15 — une exclusion ne peut JAMAIS créer un recouvrement.
     * Ce test affirmait l'inverse et encodait un bug vécu sur le club réel : « Groupe EMB ·
     * pas après 17:30 » était déclaré en contradiction BLOQUANTE avec « Groupe Adulte sauf
     * Loisir adulte · pas avant 18:50 » — deux cibles sans une seule équipe commune, et la
     * génération refusée. La raison est mathématique : (A − X) ⊆ A, donc A ∩ B = ∅ implique
     * (A−X) ∩ (B−Y) = ∅. Le conservatisme reste entier dans l'autre sens (tags PARTAGÉS +
     * exclusion → on avertit quand même, cf. le test précédent).
     */
    public function testExclusionNeverCreatesOverlapOnDisjointTargets(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00', 'targetTags' => ['ADULTE'], 'excludeTags' => ['LOISIR_ADULTE']]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTags' => ['BABY']]);

        self::assertCount(0, $this->service->detectConflicts([$c1, $c2]), 'une exclusion ne peut que RÉTRÉCIR une cible : elle ne crée jamais un recouvrement');
    }

    public function testDetectsTimeConflictRegardlessOfOrder(): void
    {
        // The min-rule is listed BEFORE the max-rule: an order-sensitive check would
        // miss it. Same-tag so the targets overlap.
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['minStartTime' => '19:00']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['maxStartTime' => '18:00']);

        self::assertCount(1, $this->service->detectConflicts([$c1, $c2]));
    }

    public function testEmptyConstraintsReturnsNoConflicts(): void
    {
        $conflicts = $this->service->detectConflicts([]);
        self::assertCount(0, $conflicts);
    }

    public function testSingleConstraintReturnsNoConflicts(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1]);
        self::assertCount(0, $conflicts);
    }

    protected function setUp(): void
    {
        $this->service = new ConstraintValidationService(new ConstraintConfigValidator);
    }
}
