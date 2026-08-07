import { useMemo } from "react";

import { anchorIsWritable, usePeriodAnchor } from "@/features/cockpit/queries";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Card, CardContent } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/utils";

import { FAMILY_LABEL, FAMILY_ORDER, groupConstraints } from "../lib/constraintOrder";
import { LEVEL_LABEL } from "../lib/labels";
import { coachMeta, groupedCoaches } from "../lib/ranking";
import { sharedSlotStatuses } from "../lib/reservationSlots";
import { coachTeamNames, countSlotsByVenue } from "../lib/summary";
import { useStepValidation } from "../lib/useStepValidation";
import { BlockerList } from "./BlockerList";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";

import { SectionCountTitle, SummaryRow, TeamTierAccordion } from "./StructureSummary";
import { useActiveTeams, useActiveVenues, useGridSlots, usePriorityTiers, useReservations, useWizardCoachPlayers, useWizardCoaches, useWizardConstraints, useWizardTeamCoaches, useWizardTeams, useWizardTeamTags, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { dayLabel, hhmm } from "../lib/days";
import { orphanReservationIds } from "../lib/orphanReservations";
import { useDeleteReservation } from "../queries";
import { Button } from "@/shared/components/ui/button";
import { Trash2 } from "lucide-react";

// Manager-facing labels for the FFBB play levels (mirrors the teams step).
/**
 * P4-44 — une ligne de réservation. ORPHELINE (créneau déplacé ou supprimé), elle se
 * signale ET s'enlève : c'est le seul écran capable de la montrer, donc le seul où le
 * geste correctif peut vivre. Le serveur, lui, bloque la génération dessus — un blocage
 * sans recours atteignable enfermerait le gestionnaire (leçon P3-20).
 */
function ReservationRow({
  reservation,
  teamName,
  venueName,
  isOrphan,
  onRemove,
  busy,
}: {
  reservation: { id: string; teamId: string; venueId: string; dayOfWeek: number; startTime: string };
  teamName: Map<string, string>;
  venueName: Map<string, string>;
  isOrphan: boolean;
  onRemove: () => void;
  busy: boolean;
}) {
  const label = teamName.get(reservation.teamId) ?? "?";
  const where = `${venueName.get(reservation.venueId) ?? "?"} · ${dayLabel(reservation.dayOfWeek)} ${hhmm(reservation.startTime)}`;

  return (
    <SummaryRow
      label={isOrphan ? <span className="text-destructive">{label} — créneau supprimé ou déplacé</span> : label}
      meta={<span className={isOrphan ? "text-destructive" : undefined}>{where}</span>}
      action={
        isOrphan ? (
          <Button variant="ghost" size="icon" className="size-7" aria-label={`Retirer la réservation de ${label}`} disabled={busy} onClick={onRemove}>
            <Trash2 className="size-4" />
          </Button>
        ) : undefined
      }
    />
  );
}

function Counter({ label, value, sub }: { label: string; value: number; sub?: string }) {
  return (
    <Card>
      <CardContent className="p-3">
        <div className="text-2xl font-semibold">{value}</div>
        <div className="text-xs text-muted-foreground">{label}</div>
        {sub ? <div className="text-xs text-muted-foreground">{sub}</div> : null}
      </CardContent>
    </Card>
  );
}

const empty = <p className="py-1.5 text-sm text-muted-foreground">—</p>;

export function RecapStep() {
  // P2-15 — le récap décrit LA PÉRIODE, pas le club : il annonçait « 49 équipes » quand 6
  // étaient cochées, et listait des gymnases désactivés. Les compteurs comptent donc les
  // ACTIFS ; les équipes en pause restent visibles, BARRÉES, dans le détail dépliable —
  // le récap sert à vérifier ce qu'on va générer, y compris ce qu'on a mis en pause.
  // (Le `planId` vient de l'ancre plus bas ; l'ordre des hooks impose de la lire d'abord.)
  const { data: coaches = [] } = useWizardCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const periodEntryId = useWizardStore((s) => (s.mode === "period" ? s.calendarEntryId : null));
  const periodAnchorEarly = usePeriodAnchor(periodEntryId);
  const layerPlanId = "period" === periodAnchorEarly.state ? periodAnchorEarly.planId : null;
  // Mode période dont l'ancre n'est PAS encore résolue : `layerPlanId` vaut null, donc les
  // listes lues sont celles de la SAISON. Les présenter comme celles de la période serait
  // le mensonge exact que ce lot corrige — on l'annonce (`useStepValidation` bloque déjà
  // la génération sur ces états ; ici c'est l'affichage qui doit cesser de mentir).
  const periodLayerUnresolved = null !== periodEntryId && "period" !== periodAnchorEarly.state;
  const { teams, pausedIds, layerRead: teamsRead } = useActiveTeams(layerPlanId);
  const { venues, layerRead: venuesRead } = useActiveVenues(layerPlanId);
  const { data: allTeams = [] } = useWizardTeams();
  const { data: allVenues = [] } = useWizardVenues();
  const { data: slots = [] } = useGridSlots(layerPlanId);
  const { data: constraints = [] } = useWizardConstraints(periodEntryId);
  // Les réservations pendent au PLAN (inv. 5, lot C3) — les contraintes, elles, restent
  // lues par l'entrée (elles décrivent le FAIT).
  // `ready` faux = plan pas encore résolu : ne PAS lire, sinon on sert le socle.
  const { data: reservations = [] } = useReservations(periodAnchorEarly.planId, anchorIsWritable(periodAnchorEarly));
  const { data: tiers = [] } = usePriorityTiers();
  const { data: tags = [] } = useWizardTeamTags();
  // Blockers live in useStepValidation("recap") so the footer "Continuer vers la
  // génération" button is gated by the same rules (single source of truth).
  const { errors: blockers } = useStepValidation("recap");

  // Les équipes en pause : hors des compteurs (elles ne seront pas générées) mais
  // VISIBLES et barrées dans le détail — on doit voir ce qu'on a mis en pause.
  const pausedTeams = allTeams.filter((t) => pausedIds.has(t.id));
  // FAIL-CLOSED (P4-20/P4-1) : tant que les overrides ne sont pas lus, on ne masque RIEN et
  // on le DIT — annoncer des chiffres de saison sur une période serait le mensonge qu'on
  // corrige. ⚠ Mais CHARGER n'est pas ÉCHOUER (revue #342 round 2) : le premier jet criait
  // « n'a pas pu être lu » sur chaque lecture en vol, donc à chaque ouverture. Un bandeau
  // d'alerte qui se déclenche en régime normal n'alerte plus de rien.
  const layerNotices = [
    periodLayerUnresolved ? { message: "Les réglages de cette période ne sont pas encore chargés — les chiffres ci-dessous sont ceux de la saison.", pending: true } : null,
    "ready" === teamsRead ? null : { message: `La sélection d'équipes de la période ${"loading" === teamsRead ? "est en cours de lecture" : "n'a pas pu être lue"} — les chiffres ci-dessous sont ceux de la saison.`, pending: "loading" === teamsRead },
    "ready" === venuesRead ? null : { message: `Les réglages de gymnases de la période ${"loading" === venuesRead ? "sont en cours de lecture" : "n'ont pas pu être lus"} — les chiffres ci-dessous sont ceux de la saison.`, pending: "loading" === venuesRead },
  ].filter((n): n is { message: string; pending: boolean } => null !== n);

  const salaried = coaches.filter((c) => c.isEmployee).length;
  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));
  const hardConstraints = constraints.filter((c) => c.ruleType === "HARD").length;
  const slotsByVenue = countSlotsByVenue(slots);

  // ⚠ NOMMER ≠ CHOISIR (revue #342). Les libellés se construisent sur les listes
  // COMPLÈTES : une réservation ou une contrainte peut viser une équipe en pause ou un
  // gymnase désactivé, et elle doit rester LISIBLE — sinon le récap affiche « ? » ou
  // « undefined » précisément là où le gestionnaire doit comprendre quoi corriger.
  // Les listes ACTIVES ne servent qu'aux compteurs et aux offres de choix.
  const teamName = new Map(allTeams.map((t) => [t.id, t.name]));
  const venueName = new Map(allVenues.map((v) => [v.id, v.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  // Créneaux PARTAGÉS (capacité ≥ 2) non ou partiellement réservés — décision P3-8 :
  // pas d'écran binômes, le récap AVERTIT sans bloquer. Deux messages distincts parce
  // que les conséquences diffèrent : non réservé = le système choisit (information) ;
  // partiel = la place restante restera VIDE (ALIGN-07 — une réservation ferme le
  // créneau entier au système), c'est une perte qu'il faut nommer.
  // Créneaux et réservations lus sur la MÊME couche (layerPlanId / plan de l'ancre).
  const sharedSlotNotices = sharedSlotStatuses(slots, reservations, new Map(allVenues.map((v) => [v.id, v.canSplit]))).map((s) => ({
    key: s.slot.id,
    partial: "partial" === s.kind,
    place: `${venueName.get(s.slot.venueId) ?? "?"} · ${dayLabel(s.slot.dayOfWeek)} ${hhmm(s.slot.startTime)}`,
    message:
      "partial" === s.kind
        ? `${s.reservedTeamIds.length} équipe(s) réservée(s) sur ${s.capacity} places — le système ne complétera pas ce créneau, la place restante restera vide. Réservez aussi l'autre équipe, ou retirez la réservation pour laisser le système choisir.`
        : `créneau partagé (${s.capacity} places) sans réservation — le système associera les équipes lui-même. Pour choisir lesquelles, réservez-les (étape Contraintes, onglet Réserver).`,
  }));
  // Reservations ordered by team rank (fanion S → A → B → C → D), then day + time.
  const teamRank = new Map(groupTeamsByTier(teams, tiers).flatMap((g) => g.teams).map((t, i) => [t.id, i]));
  const rankOf = (id: string): number => teamRank.get(id) ?? Number.MAX_SAFE_INTEGER;
  // P4-44 — les réservations devenues orphelines (créneau déplacé ou supprimé) : le
  // serveur les BLOQUE au récap, et c'est ici — seul écran qui les liste — qu'on peut
  // les retirer. L'écran « Réserver » en est incapable : sa grille boucle sur les
  // créneaux, une réservation hors grille n'y a aucune case.
  const orphanIds = orphanReservationIds(reservations, slots);
  const deleteReservation = useDeleteReservation();

  const sortedReservations = [...reservations].sort((a, b) => rankOf(a.teamId) - rankOf(b.teamId) || a.dayOfWeek - b.dayOfWeek || hhmm(a.startTime).localeCompare(hhmm(b.startTime)));
  // Coaches split into staffing groups (Salariés / Coachs-joueurs / Bénévoles),
  // each shown under its own header (user request — same as the constraint tab).
  const coachGroups = useMemo(() => {
    const g = groupedCoaches(coaches, new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId)));
    return ([["salaried", "Salariés"], ["player", "Coachs-joueurs"], ["other", "Bénévoles"]] as const).map(([k, label]) => ({ label, coaches: g[k] })).filter((s) => s.coaches.length > 0);
  }, [coaches, coachPlayers]);
  // Constraints grouped by FAMILY, then by the family's own grouping dimension
  // (coach→staffing, gymnase→venue, horaire/jours→axis) — same as each tab.
  const constraintFamilies = useMemo(() => {
    const ctx = {
      // Groupement = NOMMAGE : une contrainte visant une équipe en pause doit rester
      // listée (le compteur, lui, ne la compte pas). La filtrer la faisait disparaître.
      teams: allTeams,
      tiers,
      tags,
      coaches,
      coachPlayerIds: new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId)),
      venues: allVenues,
      coachName: (id: string) => coachName.get(id) ?? "Coach",
      venueName: (id: string) => venueName.get(id) ?? "Gymnase",
    };
    const isKnownFamily = (f: string): boolean => (FAMILY_ORDER as readonly string[]).includes(f);
    return [
      ...FAMILY_ORDER.map((family) => ({ family: family as string, sections: groupConstraints(constraints.filter((c) => c.family === family), family, ctx) })),
      { family: "OTHER", sections: groupConstraints(constraints.filter((c) => !isKnownFamily(c.family)), "OTHER", ctx) },
    ].filter((g) => g.sections.length > 0);
    // coachName/venueName are fresh Maps each render; the real inputs are the data.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [constraints, allTeams, tiers, tags, coaches, coachPlayers, allVenues]);
  // A team's main coach (fallback: its first linked coach) — shown inline in italic.
  const mainCoachName = (teamId: string): string | null => {
    const links = teamCoaches.filter((tc) => tc.teamId === teamId);
    const link = links.find((tc) => "MAIN" === tc.role) ?? links[0];
    const coach = link ? coaches.find((c) => c.id === link.coachId) : undefined;
    return coach ? `${coach.firstName} ${coach.lastName}`.trim() : null;
  };

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">{null === periodEntryId ? "Cartographie de votre club avant génération." : "Cartographie de cette période avant génération."}</p>
      {layerNotices.map((notice) => (
        <p
          key={notice.message}
          className={cn("mb-3 rounded-md px-3 py-2 text-sm", notice.pending ? "text-muted-foreground" : "border border-warning/40 bg-warning/10 text-foreground")}
        >
          {notice.message}
        </p>
      ))}
      <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Counter label="Équipes" value={teams.length} />
        <Counter label="Gymnases" value={venues.length} />
        <Counter label="Coachs" value={coaches.length} sub={`dont ${salaried} salarié(s) · ${coachPlayerIds.size} coach-joueur(s)`} />
        <Counter label="Contraintes dures" value={hardConstraints} />
      </div>

      <div className="mb-4 flex flex-col gap-1.5">
        <AccordionSection title={<SectionCountTitle label="Équipes" count={teams.length} />}>
          {0 === teams.length && 0 === pausedTeams.length ? (
            empty
          ) : (
            <TeamTierAccordion
              teams={pausedTeams.length > 0 ? [...teams, ...pausedTeams] : teams}
              // Tiers open by default: the ranks (S · Fanion → D · Bonus) must be
              // visible at first glance — collapsed inner accordions read as an
              // unsorted flat list to the manager.
              renderRow={(t) => {
                const coach = mainCoachName(t.id);
                const paused = pausedIds.has(t.id);
                return (
                  <SummaryRow
                    key={t.id}
                    label={
                      <span className={cn("flex flex-wrap items-baseline gap-x-2", paused && "text-muted-foreground")}>
                        <span className={cn(paused && "line-through")}>{t.name}</span>
                        {paused ? <span className="text-xs italic">en pause pour cette période</span> : null}
                        {/* P4-58 (c) — l'absence se DIT : une équipe sans coach affichait
                            un blanc, indistinguable d'un oubli de rendu. */}
                        <span className="text-xs italic text-muted-foreground">{coach ?? "Sans coach"}</span>
                        {t.level ? <span className="text-xs italic text-muted-foreground">· {LEVEL_LABEL[t.level]}</span> : null}
                      </span>
                    }
                    meta={`${t.sessionsPerWeek} séance(s)/sem`}
                  />
                );
              }}
            />
          )}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Gymnases" count={venues.length} />}>
          {0 === venues.length
            ? empty
            : venues.map((v) => (
                <SummaryRow
                  key={v.id}
                  label={
                    <span className="flex items-center gap-2">
                      <VenueSwatch color={v.color ?? "transparent"} className="size-3 border border-border" />
                      {v.name}
                    </span>
                  }
                  meta={`${slotsByVenue.get(v.id) ?? 0} créneau(x)`}
                />
              ))}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Coachs" count={coaches.length} />}>
          {0 === coaches.length
            ? empty
            : coachGroups.map((group) => (
                <div key={group.label} className="mb-2 last:mb-0">
                  <p className="px-1 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</p>
                  {group.coaches.map((c) => {
                    const teamsOf = coachTeamNames(c.id, teamCoaches, teamName);
                    const fullName = `${c.firstName} ${c.lastName}`.trim();
                    return (
                      <SummaryRow
                        key={c.id}
                        label={`${fullName}${teamsOf.length > 0 ? ` (${teamsOf.join(", ")})` : ""}`}
                        meta={coachMeta(c.isEmployee, coachPlayerIds.has(c.id))}
                      />
                    );
                  })}
                </div>
              ))}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Contraintes" count={constraints.length} />}>
          {0 === constraints.length
            ? empty
            : constraintFamilies.map((group) => (
                <div key={group.family} className="mb-3 last:mb-0">
                  {/* Bandeau de TITRE (demande fondateur 2026-08-05) : « Horaires/Jours/
                      Gymnase » ne se démarquait pas — fond accentué + liseré gauche. */}
                  <p className="mb-1 rounded-md border-l-4 border-accent bg-accent/10 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-foreground">{FAMILY_LABEL[group.family] ?? "Autres"}</p>
                  {group.sections.map((section) => (
                    <div key={section.key} className="mb-1 last:mb-0">
                      <p className="px-2 text-[11px] font-medium text-muted-foreground">{section.label}</p>
                      {section.items.map((c) => (
                        <SummaryRow key={c.id} label={c.name} meta={c.ruleType} />
                      ))}
                    </div>
                  ))}
                </div>
              ))}
        </AccordionSection>
        <AccordionSection title={<SectionCountTitle label="Réservations" count={reservations.length} />}>
          {0 === sortedReservations.length
            ? empty
            : (() => {
                // Sections PAR RANG (demande fondateur 2026-08-05) — le tri seul ne se
                // lisait pas : mêmes titres S/A/B/C/D que l'accordéon Équipes, une
                // réservation d'équipe hors couche (en pause) atterrit dans « Autres ».
                const groups = groupTeamsByTier(teams, tiers);
                const grouped = groups
                  .map((g) => ({ g, rows: sortedReservations.filter((r) => g.teams.some((t) => t.id === r.teamId)) }))
                  .filter(({ rows }) => rows.length > 0);
                const known = new Set(grouped.flatMap(({ rows }) => rows.map((r) => r.id)));
                const orphanRows = sortedReservations.filter((r) => !known.has(r.id));
                return (
                  <>
                    {grouped.map(({ g, rows }) => (
                      <div key={g.tier?.id ?? "orphan"} className="mb-2 last:mb-0">
                        <p className="px-1 pb-0.5 pt-1 text-xs font-semibold text-muted-foreground">{tierGroupLabel(g.tier)}</p>
                        {rows.map((r) => (
                          <ReservationRow key={r.id} reservation={r} teamName={teamName} venueName={venueName} isOrphan={orphanIds.has(r.id)} onRemove={() => deleteReservation.mutate(r.id)} busy={deleteReservation.isPending} />
                        ))}
                      </div>
                    ))}
                    {orphanRows.length > 0 ? (
                      <div className="mb-2 last:mb-0">
                        <p className="px-1 pb-0.5 pt-1 text-xs font-semibold text-muted-foreground">Autres</p>
                        {orphanRows.map((r) => (
                          <ReservationRow key={r.id} reservation={r} teamName={teamName} venueName={venueName} isOrphan={orphanIds.has(r.id)} onRemove={() => deleteReservation.mutate(r.id)} busy={deleteReservation.isPending} />
                        ))}
                      </div>
                    ) : null}
                  </>
                );
              })()}
        </AccordionSection>
      </div>

      {/* Encarts créneaux partagés AVEC les bloqueurs, au-dessus du bouton (demande
          fondateur 2026-08-04) : tout ce qui pèse sur la décision de lancer vit au
          même endroit — en haut ils étaient incohérents avec les warnings du bas. */}
      {sharedSlotNotices.map((notice) => (
        <p
          key={notice.key}
          className={cn(
            "mb-3 rounded-md px-3 py-2 text-sm",
            // Partiel = warning (une place se PERD) ; non réservé = information neutre
            // (le système fera un choix légitime). Même œil que layerNotices : le ton
            // visuel suit la gravité, sinon tout bandeau finit par ne plus rien dire.
            notice.partial ? "border border-warning/40 bg-warning/10 text-foreground" : "border border-border bg-muted/50 text-muted-foreground",
          )}
        >
          <span className="font-medium">{notice.place}</span> : {notice.message}
        </p>
      ))}
      {blockers.length > 0 ? (
        <BlockerList blockers={blockers} className="mb-4" />
      ) : (
        <p className="text-sm text-success">Tout est prêt. Utilisez « Continuer vers la génération » en bas pour lancer.</p>
      )}
      {/* Respiration avant la barre du bouton « Continuer » (demande fondateur). */}
      <div className="h-4" aria-hidden />
    </div>
  );
}
