import { ChevronLeft, ChevronRight, Plus } from "lucide-react";
import { useMemo } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Spinner } from "@/shared/components/ui/spinner";

import type { Category, Coach, Fixture, Team, Venue } from "./api";
import { ConflictRadar } from "./ConflictRadar";
import { FixtureFormDialog } from "./FixtureFormDialog";
import { isInEnvelope, resolveEnvelope } from "./lib/envelope";
import { buildWeekendGrid, isPlacedOnGrid, listWeekends, weekendKeyOf, weekendLabel } from "./lib/weekendGrid";
import { PlacementPanel } from "./PlacementPanel";
import { useCategories, useCoaches, useCompetitions, useConflicts, useFixtures, useLeagueWindows, usePlaceFixture, useTeams, useVenues } from "./queries";
import { useMatchesStore } from "./store";
import { UnplacedList } from "./UnplacedList";
import { WeekendGrid } from "./WeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

export function MatchesPage() {
  const fixtures = useFixtures();
  const competitions = useCompetitions();
  const leagueWindows = useLeagueWindows();
  const conflicts = useConflicts();
  const teams = useTeams();
  const venues = useVenues();
  const categories = useCategories();
  const coaches = useCoaches();
  const placeFixture = usePlaceFixture();

  const { selectedWeekend, selectedFixtureId, fixtureFormOpen, setSelectedWeekend, setSelectedFixtureId, setFixtureFormOpen } = useMatchesStore();

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const categoriesMap = useMemo<Map<string, Category>>(() => byId(categories.data), [categories.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);

  const allFixtures = useMemo<Fixture[]>(() => fixtures.data ?? [], [fixtures.data]);
  const windows = useMemo(() => leagueWindows.data?.items ?? [], [leagueWindows.data]);

  // Placed home fixtures out of their league envelope (only when the team maps).
  const outOfEnvelope = useMemo<Set<string>>(() => {
    const set = new Set<string>();
    for (const fixture of allFixtures) {
      if (!isPlacedOnGrid(fixture) || null === fixture.kickoffTime) {
        continue;
      }
      const envelope = resolveEnvelope(fixture, teamsMap, categoriesMap, windows);
      if (envelope.mapped && !isInEnvelope(envelope, fixture.kickoffTime)) {
        set.add(fixture.id);
      }
    }
    return set;
  }, [allFixtures, teamsMap, categoriesMap, windows]);

  const weekends = useMemo(() => listWeekends(allFixtures), [allFixtures]);
  const activeWeekend = selectedWeekend ?? weekends[0] ?? null;
  const weekendIndex = null === activeWeekend ? -1 : weekends.indexOf(activeWeekend);

  const weekendFixtures = useMemo(
    () => (null === activeWeekend ? [] : allFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [allFixtures, activeWeekend],
  );

  const grid = useMemo(() => buildWeekendGrid(weekendFixtures, venuesMap, teamsMap, outOfEnvelope), [weekendFixtures, venuesMap, teamsMap, outOfEnvelope]);

  const selectedFixture = allFixtures.find((f) => f.id === selectedFixtureId) ?? null;
  const selectedEnvelope = useMemo(
    () => (null === selectedFixture ? null : resolveEnvelope(selectedFixture, teamsMap, categoriesMap, windows)),
    [selectedFixture, teamsMap, categoriesMap, windows],
  );

  if (fixtures.isLoading || teams.isLoading || venues.isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-2">
        <h1 className="text-lg font-semibold">Matchs</h1>
        <Button size="sm" onClick={() => setFixtureFormOpen(true)}>
          <Plus className="size-4" />
          Nouveau match
        </Button>
      </div>

      <div className="grid gap-4 lg:grid-cols-[20rem_1fr]">
        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">À placer</CardTitle>
            </CardHeader>
            <CardContent>
              <UnplacedList fixtures={allFixtures} teams={teamsMap} selectedFixtureId={selectedFixtureId} onSelect={setSelectedFixtureId} />
            </CardContent>
          </Card>

          {null !== selectedFixture && null !== selectedEnvelope && "HOME" === selectedFixture.homeAway ? (
            <PlacementPanel
              key={selectedFixture.id}
              fixture={selectedFixture}
              venues={venues.data ?? []}
              teamLabel={teamsMap.get(selectedFixture.teamId)?.name ?? "Équipe ?"}
              categoryLabel={categoriesMap.get(teamsMap.get(selectedFixture.teamId)?.sportCategoryId ?? "")?.name ?? "—"}
              envelope={selectedEnvelope}
              busy={placeFixture.isPending}
              onClose={() => setSelectedFixtureId(null)}
              onPlace={(input) =>
                placeFixture.mutate({ fixture: selectedFixture, input }, { onSuccess: () => setSelectedFixtureId(null) })
              }
            />
          ) : null}

          <ConflictRadar conflicts={conflicts.data?.conflicts ?? []} teams={teamsMap} coaches={coachesMap} />
        </div>

        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between gap-2">
            <Button variant="outline" size="sm" disabled={weekendIndex <= 0} onClick={() => setSelectedWeekend(weekends[weekendIndex - 1] ?? null)} aria-label="Week-end précédent">
              <ChevronLeft className="size-4" />
            </Button>
            <span className="text-sm font-medium">{null === activeWeekend ? "Aucun match" : weekendLabel(activeWeekend)}</span>
            <Button
              variant="outline"
              size="sm"
              disabled={weekendIndex < 0 || weekendIndex >= weekends.length - 1}
              onClick={() => setSelectedWeekend(weekends[weekendIndex + 1] ?? null)}
              aria-label="Week-end suivant"
            >
              <ChevronRight className="size-4" />
            </Button>
          </div>
          <div className="h-[32rem]">
            <WeekendGrid model={grid} />
          </div>
        </div>
      </div>

      {fixtureFormOpen ? <FixtureFormDialog teams={teams.data ?? []} competitions={competitions.data ?? []} onClose={() => setFixtureFormOpen(false)} /> : null}
    </div>
  );
}
