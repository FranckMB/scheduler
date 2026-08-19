<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConstraintScope;

/**
 * P4-57 — les préventions qu'on peut FAIRE AVANT de générer.
 *
 * Décision fondateur du 2026-08-06, reconduite ici : « on peut s'en prémunir » plutôt que le
 * découvrir après génération. Chacun des quatre constats ci-dessous n'existait jusqu'ici que
 * comme diagnostic POST-solve (« l'équipe n'a pas pu être placée : aucun créneau ne convient »,
 * {@see DiagnosticMessageBuilder}) — le gestionnaire attendait donc une génération complète pour
 * apprendre une chose qui se voyait dans ses données.
 *
 * ⚑ Tout se calcule depuis LE PAYLOAD, celui-là même qui part au moteur — jamais depuis une
 * lecture parallèle de la base. C'est ce qui garantit qu'un avertissement décrit ce que le
 * solveur va RÉELLEMENT recevoir : le filtre de période, les overrides et les désactivations
 * sont déjà appliqués quand on arrive ici. Le même principe que la parité gate ⇄ payload.
 *
 * Ces messages AVERTISSENT, ils ne bloquent jamais : le solveur reste l'autorité, et un club
 * peut vouloir générer en connaissance de cause.
 */
final readonly class PreSolvePreventionWarnings
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function detect(array $payload): array
    {
        $venues = $this->listOf($payload, 'venues');
        $teams = $this->listOf($payload, 'teams');
        $constraints = $this->listOf($payload, 'constraints');

        return [
            ...$this->venuesWithoutSlots($venues),
            ...$this->teamsNoSlotCanHost($teams, $venues, $constraints),
            ...$this->overloadedCoaches($payload, $teams, $venues, $constraints),
            ...$this->constraintsTargetingAbsentTeams($teams, $constraints),
        ];
    }

    /**
     * 2. Un gymnase déclaré auquel aucune disponibilité n'a été ajoutée.
     *
     * Presque toujours une saisie inachevée : la salle existe pour le club mais n'offre rien
     * au solveur. Silencieux jusqu'ici — elle n'apparaissait simplement nulle part.
     *
     * @param list<array<string, mixed>> $venues
     *
     * @return list<string>
     */
    private function venuesWithoutSlots(array $venues): array
    {
        $names = [];
        foreach ($venues as $venue) {
            if ([] === $this->listOf($venue, 'trainingSlots')) {
                $names[] = $this->nameOf($venue);
            }
        }

        if ([] === $names) {
            return [];
        }

        sort($names);

        return [\sprintf(
            1 === \count($names)
                ? 'Le gymnase %s n\'a aucun créneau : il ne servira pas à la génération. Ajoutez ses disponibilités, ou retirez-le.'
                : 'Ces gymnases n\'ont aucun créneau et ne serviront pas à la génération : %s. Ajoutez leurs disponibilités, ou retirez-les.',
            implode(', ', $names),
        )];
    }

    /**
     * 1. Une équipe qu'AUCUN créneau ne peut accueillir.
     *
     * Deux causes, cumulables : son gymnase IMPOSÉ n'offre rien, et/ou ses jours autorisés ne
     * croisent aucun créneau ouvert. Dans les deux cas le placement est impossible AVANT même
     * de lancer le solveur — c'est une certitude arithmétique, pas un risque.
     *
     * ⚠ On ne regarde que ce qui rend l'accueil IMPOSSIBLE, jamais improbable : un créneau
     * déjà pris par une autre équipe n'entre pas ici (c'est l'affaire du solveur, et la
     * capacité globale est déjà couverte par le récap). Un avertissement qui crie au loup
     * cesse d'être lu.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function teamsNoSlotCanHost(array $teams, array $venues, array $constraints): array
    {
        $slotDaysByVenue = [];
        $allDays = [];
        foreach ($venues as $venue) {
            $venueId = $this->stringOf($venue, 'id');
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $day = (int) ($slot['dayOfWeek'] ?? 0);
                $slotDaysByVenue[$venueId][$day] = true;
                $allDays[$day] = true;
            }
        }

        $messages = [];
        foreach ($teams as $team) {
            $teamId = $this->stringOf($team, 'id');
            $forcedVenueId = $this->stringOf($team, 'forcedVenueId');
            $reachable = '' !== $forcedVenueId ? ($slotDaysByVenue[$forcedVenueId] ?? []) : $allDays;

            if ([] === $reachable) {
                $messages[] = \sprintf(
                    '%s est imposée dans un gymnase qui n\'a aucun créneau : elle ne pourra pas être placée.',
                    $this->nameOf($team),
                );
                continue;
            }

            $allowed = $this->allowedDaysOf($teamId, $constraints);
            if (null !== $allowed && [] === array_intersect_key($reachable, array_flip($allowed))) {
                $messages[] = \sprintf(
                    '%s n\'autorise que des jours où aucun créneau ne lui est ouvert : elle ne pourra pas être placée.',
                    $this->nameOf($team),
                );
            }
        }

        sort($messages);

        return $messages;
    }

    /**
     * 3. Un coach dont la charge dépasse ses jours disponibles.
     *
     * Somme des séances hebdomadaires de SES équipes contre le nombre de jours où un créneau
     * lui reste ouvert (ses indisponibilités retirées). Au-dessus, il devra cumuler plusieurs
     * séances le même jour — ce que le solveur sait faire, mais que le gestionnaire gagne à
     * décider plutôt qu'à subir.
     *
     * ⚠ AVERTISSEMENT et non bloqueur, précisément parce que le cumul est légal : deux séances
     * le même soir dans deux gymnases voisins peuvent très bien convenir. C'est le
     * dédoublement STRICT (même heure, deux endroits) qui bloque, et il est déjà traité
     * ailleurs (`CoachDoubleBookingDetector`).
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function overloadedCoaches(array $payload, array $teams, array $venues, array $constraints): array
    {
        $openDays = [];
        foreach ($venues as $venue) {
            foreach ($this->listOf($venue, 'trainingSlots') as $slot) {
                $openDays[(int) ($slot['dayOfWeek'] ?? 0)] = true;
            }
        }
        if ([] === $openDays) {
            return []; // Aucun créneau du tout : le récap le dit déjà, mieux et plus tôt.
        }

        $sessionsByTeam = [];
        foreach ($teams as $team) {
            $sessionsByTeam[$this->stringOf($team, 'id')] = (int) ($team['sessionsPerWeek'] ?? 0);
        }

        // ⚠ Le lien équipe⇄coach voyage en forme **v1** dans le payload (`type: TEAM_COACH`,
        // `teamId`, `metadata.coachId`) — PAS en contrainte v2 : il n'y a pas de famille
        // `TEAM_COACH`, les familles sont TIME/DAY/FACILITY/COACH_AVAILABILITY. Lire ici un
        // `family` aurait rendu ce warning silencieusement inerte, jamais faux : le pire des
        // deux mondes, un garde qui ne garde rien sans que rien ne le dise.
        $load = [];
        foreach ($constraints as $constraint) {
            if ('TEAM_COACH' !== ($constraint['type'] ?? null)) {
                continue;
            }
            $metadata = \is_array($constraint['metadata'] ?? null) ? $constraint['metadata'] : [];
            $coachId = $this->stringOf($metadata, 'coachId');
            $teamId = $this->stringOf($constraint, 'teamId');
            if ('' === $coachId || '' === $teamId) {
                continue;
            }
            $load[$coachId] = ($load[$coachId] ?? 0) + ($sessionsByTeam[$teamId] ?? 0);
        }

        $messages = [];
        foreach ($this->listOf($payload, 'coaches') as $coach) {
            $coachId = $this->stringOf($coach, 'id');
            $sessions = $load[$coachId] ?? 0;
            if (0 === $sessions) {
                continue;
            }

            $available = array_diff(array_keys($openDays), $this->unavailableDaysOf($coachId, $constraints));
            if ($sessions > \count($available)) {
                $messages[] = \sprintf(
                    '%s encadre %d séances par semaine mais n\'a que %d jour%s disponible%s : plusieurs séances tomberont le même jour.',
                    trim(($coach['firstName'] ?? '') . ' ' . ($coach['lastName'] ?? '')) ?: 'Ce coach',
                    $sessions,
                    \count($available),
                    1 === \count($available) ? '' : 's',
                    1 === \count($available) ? '' : 's',
                );
            }
        }

        sort($messages);

        return $messages;
    }

    /**
     * 4. Une contrainte qui vise une équipe absente du périmètre.
     *
     * Sur un plan de période, le payload ne porte que les équipes ENGAGÉES sur la fenêtre : une
     * contrainte visant une équipe désactivée y survit sans jamais pouvoir s'appliquer. Le
     * gestionnaire croit avoir réglé quelque chose qui ne s'exercera pas.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<string>
     */
    private function constraintsTargetingAbsentTeams(array $teams, array $constraints): array
    {
        $present = [];
        foreach ($teams as $team) {
            $present[$this->stringOf($team, 'id')] = true;
        }

        $orphans = 0;
        foreach ($constraints as $constraint) {
            if (ConstraintScope::TEAM->value !== ($constraint['scope'] ?? null)) {
                continue;
            }
            $teamId = $this->stringOf($constraint, 'scopeTargetId');
            if ('' !== $teamId && !isset($present[$teamId])) {
                ++$orphans;
            }
        }

        if (0 === $orphans) {
            return [];
        }

        return [\sprintf(
            1 === $orphans
                ? 'Une contrainte vise une équipe absente de cette période : elle ne s\'appliquera pas.'
                : '%d contraintes visent des équipes absentes de cette période : elles ne s\'appliqueront pas.',
            $orphans,
        )];
    }

    /**
     * Les jours autorisés d'une équipe, ou `null` quand elle n'en restreint aucun.
     *
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<int>|null
     */
    private function allowedDaysOf(string $teamId, array $constraints): ?array
    {
        $allowed = null;
        foreach ($constraints as $constraint) {
            if ($this->stringOf($constraint, 'scopeTargetId') !== $teamId) {
                continue;
            }
            $days = ($constraint['config'] ?? [])['allowedDays'] ?? null;
            if (!\is_array($days) || [] === $days) {
                continue;
            }
            $current = array_map(intval(...), array_values($days));
            // Deux whitelists se CROISENT (chacune est une restriction), elles ne s'ajoutent pas.
            $allowed = null === $allowed ? $current : array_values(array_intersect($allowed, $current));
        }

        return $allowed;
    }

    /**
     * @param list<array<string, mixed>> $constraints
     *
     * @return list<int>
     */
    private function unavailableDaysOf(string $coachId, array $constraints): array
    {
        $days = [];
        foreach ($constraints as $constraint) {
            if (ConstraintScope::COACH->value !== ($constraint['scope'] ?? null) || $this->stringOf($constraint, 'scopeTargetId') !== $coachId) {
                continue;
            }
            foreach (($constraint['config'] ?? [])['unavailableDays'] ?? [] as $day) {
                $days[] = (int) $day;
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return \is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /** @param array<string, mixed> $source */
    private function stringOf(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $entity */
    private function nameOf(array $entity): string
    {
        $name = $this->stringOf($entity, 'name');

        return '' !== $name ? $name : 'Sans nom';
    }
}
