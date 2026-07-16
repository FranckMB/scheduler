import { ChevronLeft, ChevronRight, Lock, Plus, Upload } from "lucide-react";
import { useMemo } from "react";

import { useMe } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Spinner } from "@/shared/components/ui/spinner";

import type { Category, Coach, Fixture, Team, Venue } from "./api";
import { ConflictRadar } from "./ConflictRadar";
import { FixtureFormDialog } from "./FixtureFormDialog";
import { ImportFbiDialog } from "./ImportFbiDialog";
import { isInEnvelope, resolveEnvelope } from "./lib/envelope";
import { buildWeekendGrid, isPlacedOnGrid, listWeekends, weekendKeyOf, weekendLabel } from "./lib/weekendGrid";
import { PlacementPanel } from "./PlacementPanel";
import { useCategories, useCoaches, useCompetitions, useConflicts, useFixtures, useLeagueWindows, usePlaceFixture, usePriorityTiers, useTeams, useVenues } from "./queries";
import { useMatchesStore } from "./store";
import { UnplacedList } from "./UnplacedList";
import { WeekendGrid } from "./WeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

export function MatchesPage() {
  const { data: me } = useMe();
  const fixtures = useFixtures();
  const competitions = useCompetitions();
  const leagueWindows = useLeagueWindows();
  const conflicts = useConflicts();
  const teams = useTeams();
  const priorityTiers = usePriorityTiers();
  const venues = useVenues();
  const categories = useCategories();
  const coaches = useCoaches();
  const placeFixture = usePlaceFixture();

  const { selectedWeekend, selectedFixtureId, fixtureFormOpen, importDialogOpen, setSelectedWeekend, setSelectedFixtureId, setFixtureFormOpen, setImportDialogOpen } =
    useMatchesStore();

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

  // Matches are locked until the season's plan points at a version (cockpit
  // state 2) — the same condition the server's SocleGuard enforces on writes.
  if (null == me?.seasonPlan?.chosenScheduleId) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <Lock className="mx-auto mb-3 size-8 text-accent" />
        <h1 className="mb-1 text-lg font-semibold">Matchs verrouillés</h1>
        <p className="text-sm text-muted-foreground">Validez d'abord votre planning principal (accueil → Ouvrir) pour débloquer les matchs.</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-2">
        <h1 className="border-l-[3px] border-accent pl-3 text-lg font-semibold">Matchs</h1>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => setImportDialogOpen(true)}>
            <Upload className="size-4" />
            Importer FBI
          </Button>
          <Button size="sm" onClick={() => setFixtureFormOpen(true)}>
            <Plus className="size-4" />
            Nouveau match
          </Button>
        </div>
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

          {/* Trois états, jamais deux : « pas de conflit » ne doit pas se confondre avec
              « je n'ai pas pu regarder ». Sans ça, une requête en échec affiche le
              ShieldCheck vert « Aucun conflit détecté » et fait poser un match sur un
              entraînement vivant — exactement ce qui a été corrigé dans RadarPanel. */}
          {false === conflicts.data?.seasonPlanChosen ? (
            // Le planning a été rouvert (ici, ou dans un autre onglet — /api/me peut
            // avoir jusqu'à 60 s de retard) : sans calendrier, rien n'est détectable.
            <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-foreground">
              Le planning de la saison n'est plus validé — les conflits avec les entraînements ne sont pas évalués.
            </p>
          ) : conflicts.isError ? (
            <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-foreground">
              Les conflits n'ont pas pu être vérifiés — rechargez la page avant de placer un match.
            </p>
          ) : null}
          {undefined === conflicts.data ? null : (
            <ConflictRadar conflicts={conflicts.data.conflicts} teams={teamsMap} coaches={coachesMap} />
          )}
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

      {fixtureFormOpen ? <FixtureFormDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} competitions={competitions.data ?? []} onClose={() => setFixtureFormOpen(false)} /> : null}
      {importDialogOpen ? <ImportFbiDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} onClose={() => setImportDialogOpen(false)} /> : null}
    </div>
  );
}
