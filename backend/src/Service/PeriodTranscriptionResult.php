<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Le résultat d'une transcription du socle vers une V1 de période ({@see PeriodPlanTranscriber}) :
 * la version créée, et la liste « à replacer » — le SOUS-PRODUIT servi tel quel au front (il ne
 * redérive rien). Chaque ligne « à replacer » nomme équipe + jour + heure + gymnase d'origine + la
 * raison de sa non-copie (fermeture, gymnase désactivé, ou équipe réduite).
 */
final readonly class PeriodTranscriptionResult
{
    /**
     * @param list<array{teamId: string, dayOfWeek: int, startTime: string, venueId: string, reason: string}> $toReplace
     */
    public function __construct(
        public string $scheduleId,
        public int $versionNumber,
        public int $copiedCount,
        public array $toReplace,
    ) {}
}
