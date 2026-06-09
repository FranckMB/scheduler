<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Venue;
use App\Entity\Team;
use App\Entity\Coach;
use App\Entity\TeamConstraint;

class ScheduleConstraintBuilder
{
    public function build(array $venues, array $teams, array $coaches, array $constraints): array
    {
        $clubId = null;
        $seasonId = null;
        foreach ($venues as $venue) {
            if ($venue instanceof Venue) {
                $clubId = $venue->getClubId();
                $seasonId = $venue->getSeasonId();
                break;
            }
        }

        return [
            "version" => "1.0",
            "club_id" => $clubId ?? "test-club",
            "season_id" => $seasonId ?? "test-season",
            "venues" => array_map(fn(Venue $v) => [
                "id" => $v->getId(),
                "name" => $v->getName(),
                "is_external" => $v->getIsExternal(),
            ], $venues),
            "teams" => array_map(fn(Team $t) => [
                "id" => $t->getId(),
                "name" => $t->getName(),
                "sessions_per_week" => $t->getSessionsPerWeek(),
                "sport_category_id" => $t->getSportCategoryId(),
            ], $teams),
            "coaches" => array_map(fn(Coach $c) => [
                "id" => $c->getId(),
                "first_name" => $c->getFirstName(),
                "last_name" => $c->getLastName(),
            ], $coaches),
            "constraints" => array_map(fn(TeamConstraint $c) => [
                "id" => $c->getId(),
                "team_id" => $c->getTeamId(),
                "type" => $c->getType(),
            ], $constraints),
        ];
    }
}
