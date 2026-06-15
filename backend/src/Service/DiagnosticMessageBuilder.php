<?php

declare(strict_types=1);

namespace App\Service;

final class DiagnosticMessageBuilder
{
    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames  teamId => name
     * @param array<string, string> $coachNames coachId => name
     * @param array<string, string> $venueNames venueId => name
     */
    public function build(
        array $diagnostic,
        array $teamNames = [],
        array $coachNames = [],
        array $venueNames = [],
    ): string {
        $type = (string) ($diagnostic['type'] ?? '');

        return match ($type) {
            'unplaced' => $this->buildUnplaced($diagnostic, $teamNames),
            'conflict' => $this->buildConflict($diagnostic, $teamNames, $coachNames, $venueNames),
            'coach_overload' => $this->buildCoachOverload($diagnostic, $coachNames),
            'soft_lock_moved' => $this->buildSoftLockMoved($diagnostic, $teamNames, $venueNames),
            default => (string) ($diagnostic['message'] ?? 'Diagnostic inconnu.'),
        };
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     */
    private function buildUnplaced(array $diagnostic, array $teamNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'L\'équipe';

        return \sprintf(
            '%s n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $teamName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     * @param array<string, string> $coachNames
     * @param array<string, string> $venueNames
     */
    private function buildConflict(
        array $diagnostic,
        array $teamNames,
        array $coachNames,
        array $venueNames,
    ): string {
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $coachId = $this->extractId($diagnostic, 'coachId', 'coach_id');

        if (null !== $venueId) {
            $venueName = $venueNames[$venueId] ?? $venueId;

            return \sprintf(
                '%s accueille plusieurs équipes simultanément. Veuillez déplacer l\'une des séances.',
                $venueName,
            );
        }

        if (null !== $coachId) {
            $coachName = $coachNames[$coachId] ?? $coachId;

            return \sprintf(
                '%s est assigné(e) à plusieurs équipes simultanément. Veuillez réattribuer l\'une des séances.',
                $coachName,
            );
        }

        return 'Le planning n\'a pas pu être généré : les contraintes actuelles sont incompatibles.';
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $coachNames
     */
    private function buildCoachOverload(array $diagnostic, array $coachNames): string
    {
        $coachId = $this->extractId($diagnostic, 'coachId', 'coach_id');
        $coachName = null !== $coachId ? ($coachNames[$coachId] ?? $coachId) : 'L\'entraîneur';
        $count = (int) ($diagnostic['count'] ?? 0);
        $threshold = (int) ($diagnostic['threshold'] ?? 0);

        if ($count > 0 && $threshold > 0) {
            return \sprintf(
                '%s est surchargé(e) avec %d séances (limite recommandée : %d).',
                $coachName,
                $count,
                $threshold,
            );
        }

        return \sprintf(
            '%s est surchargé(e). Veuillez réduire son nombre de séances.',
            $coachName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     * @param array<string, string> $venueNames
     */
    private function buildSoftLockMoved(array $diagnostic, array $teamNames, array $venueNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'L\'équipe';
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $venueName = null !== $venueId ? ($venueNames[$venueId] ?? $venueId) : null;

        if (null !== $venueName) {
            return \sprintf(
                'Le créneau préféré de %s (%s) a été déplacé par le solveur pour un meilleur ajustement global.',
                $teamName,
                $venueName,
            );
        }

        return \sprintf(
            'Le créneau préféré de %s a été déplacé par le solveur pour un meilleur ajustement global.',
            $teamName,
        );
    }

    /** @param array<string, mixed> $diagnostic */
    private function extractId(array $diagnostic, string $camelKey, string $snakeKey): ?string
    {
        if (isset($diagnostic[$camelKey])) {
            return (string) $diagnostic[$camelKey];
        }

        if (isset($diagnostic[$snakeKey])) {
            return (string) $diagnostic[$snakeKey];
        }

        return null;
    }
}
