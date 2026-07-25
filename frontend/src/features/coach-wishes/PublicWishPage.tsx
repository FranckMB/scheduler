import { useMemo, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useParams } from "react-router";

import { AuthLayout } from "@/features/auth/AuthLayout";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Spinner } from "@/shared/components/ui/spinner";

import { getPublicWishContext, isPublicWishError, submitPublicWishes, type PublicWishContext, type PublicWishSubmission } from "./publicApi";

/** Jours ISO 1–7 (1 = lundi) et leur libellé court FR. */
const DAY_LABELS: { day: number; label: string }[] = [
  { day: 1, label: "Lun" },
  { day: 2, label: "Mar" },
  { day: 3, label: "Mer" },
  { day: 4, label: "Jeu" },
  { day: 5, label: "Ven" },
  { day: 6, label: "Sam" },
  { day: 7, label: "Dim" },
];

/** État éditable d'une section (équipe × semaine). */
interface SectionState {
  slotsWanted: number;
  days: Set<number>;
  comment: string;
}

const frDate = (iso: string): string => {
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
};

const sectionKey = (teamId: string, weekStart: string): string => `${teamId}|${weekStart}`;

/**
 * Page publique de collecte (feature #10, lot C2) — SANS LOGIN. Le coach ouvre son lien
 * `/doleances/:token` : ses équipes retenues × les semaines, pré-remplies de l'état courant
 * (y compris les saisies « au nom de » du gestionnaire). Il n'envoie que les sections qu'il
 * MODIFIE (dirty-tracking) — une section non touchée n'écrit rien et ne remet pas « à traiter »
 * une doléance déjà traitée. Réutilisable/révisable jusqu'à la deadline.
 */
export function PublicWishPage() {
  const { token = "" } = useParams();

  const query = useQuery({
    queryKey: ["public-wish", token],
    queryFn: () => getPublicWishContext(token),
    retry: false,
    staleTime: 0,
  });

  if (query.isLoading) {
    return (
      <AuthLayout title="Vos disponibilités" description="Chargement…">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          Un instant…
        </p>
      </AuthLayout>
    );
  }

  if (query.isError) {
    const status = isPublicWishError(query.error) ? query.error.response.status : 0;
    if (410 === status) {
      return (
        <AuthLayout title="Lien expiré" description="La collecte est close.">
          <p className="text-sm text-muted-foreground">La période de collecte est terminée. Rapprochez-vous de votre club si vous souhaitez encore transmettre vos disponibilités.</p>
        </AuthLayout>
      );
    }
    return (
      <AuthLayout title="Lien invalide" description="Ce lien n'est pas reconnu.">
        <p className="text-sm text-muted-foreground">Ce lien est invalide. Vérifiez qu'il est complet, ou demandez-en un nouveau à votre club.</p>
      </AuthLayout>
    );
  }

  if (undefined === query.data) {
    return null;
  }
  return <PublicWishForm token={token} context={query.data} />;
}

function PublicWishForm({ token, context }: { token: string; context: PublicWishContext }) {
  // Snapshot initial (état courant pré-rempli) — sert de référence au dirty-tracking.
  const initial = useMemo(() => {
    const map = new Map<string, SectionState>();
    for (const team of context.teams) {
      for (const week of context.weeks) {
        const existing = context.wishes.find((w) => w.teamId === team.id && w.weekStart === week);
        map.set(sectionKey(team.id, week), {
          slotsWanted: existing?.slotsWanted ?? 0,
          days: new Set(existing?.unavailableDays ?? []),
          comment: existing?.comment ?? "",
        });
      }
    }
    return map;
  }, [context]);

  const [sections, setSections] = useState<Map<string, SectionState>>(() => new Map([...initial].map(([k, v]) => [k, { slotsWanted: v.slotsWanted, days: new Set(v.days), comment: v.comment }])));
  const [done, setDone] = useState(false);

  const mutation = useMutation({
    mutationFn: (submissions: PublicWishSubmission[]) => submitPublicWishes(token, submissions),
    onSuccess: () => setDone(true),
  });

  const patch = (key: string, next: Partial<SectionState>) =>
    setSections((prev) => {
      const map = new Map(prev);
      const cur = map.get(key);
      if (undefined === cur) {
        return prev;
      }
      map.set(key, { ...cur, ...next });
      return map;
    });

  const toggleDay = (key: string, day: number) =>
    setSections((prev) => {
      const map = new Map(prev);
      const cur = map.get(key);
      if (undefined === cur) {
        return prev;
      }
      const days = new Set(cur.days);
      if (days.has(day)) {
        days.delete(day);
      } else {
        days.add(day);
      }
      map.set(key, { ...cur, days });
      return map;
    });

  // Une section est MODIFIÉE si elle diffère de l'état initial — seules celles-là partent.
  const isDirty = (key: string): boolean => {
    const a = sections.get(key);
    const b = initial.get(key);
    if (undefined === a || undefined === b) {
      return false;
    }
    if (a.slotsWanted !== b.slotsWanted || a.comment.trim() !== b.comment.trim() || a.days.size !== b.days.size) {
      return true;
    }
    for (const d of a.days) {
      if (!b.days.has(d)) {
        return true;
      }
    }
    return false;
  };

  const dirtyKeys = [...sections.keys()].filter(isDirty);

  const submit = () => {
    const submissions: PublicWishSubmission[] = dirtyKeys.map((key) => {
      const [teamId, weekStart] = key.split("|");
      const s = sections.get(key) as SectionState;
      return {
        teamId,
        weekStart,
        slotsWanted: s.slotsWanted,
        unavailableDays: [...s.days].sort((x, y) => x - y),
        comment: s.comment.trim() || null,
      };
    });
    mutation.mutate(submissions);
  };

  if (0 === context.teams.length) {
    return (
      <AuthLayout title="Aucune équipe concernée" description={context.periodTitle}>
        <p className="text-sm text-muted-foreground">Aucune de vos équipes n'est concernée par cette collecte pour le moment. Rapprochez-vous de votre club.</p>
      </AuthLayout>
    );
  }

  if (done) {
    return (
      <AuthLayout title="Merci !" description="Vos disponibilités sont enregistrées.">
        <p className="text-sm text-muted-foreground">C'est transmis à votre club. Vous pouvez revenir modifier ce formulaire jusqu'au {frDate(context.deadline)}.</p>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={`Bonjour ${context.coachFirstName}`} description={`${context.periodTitle} · à renvoyer avant le ${frDate(context.deadline)}`}>
      <p className="mb-4 text-sm text-muted-foreground">Indiquez, pour chaque équipe et chaque semaine, combien de séances vous souhaitez et vos jours d'indisponibilité. C'est un souhait, pas un engagement.</p>
      <div className="space-y-4">
        {context.teams.map((team) =>
          context.weeks.map((week) => {
            const key = sectionKey(team.id, week);
            const s = sections.get(key) as SectionState;
            return (
              <fieldset key={key} className="rounded-lg border border-border bg-card p-3">
                <legend className="px-1 text-sm font-medium">
                  {team.name} · semaine du {frDate(week)}
                </legend>
                <label className="mt-2 flex items-center gap-2 text-sm">
                  Séances souhaitées
                  <Input
                    type="number"
                    min={0}
                    max={7}
                    aria-label={`Séances souhaitées — ${team.name}, semaine du ${frDate(week)}`}
                    className="h-8 w-16"
                    value={s.slotsWanted}
                    onChange={(e) => patch(key, { slotsWanted: Math.max(0, Math.min(7, Number(e.target.value) || 0)) })}
                  />
                </label>
                <div className="mt-2">
                  <span className="text-sm text-muted-foreground">Jours d'indisponibilité</span>
                  <div className="mt-1 flex flex-wrap gap-1.5">
                    {DAY_LABELS.map(({ day, label }) => (
                      <label key={day} className="flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs">
                        <input type="checkbox" className="size-3.5 accent-[var(--accent)]" checked={s.days.has(day)} onChange={() => toggleDay(key, day)} aria-label={`${label} indisponible — ${team.name}, semaine du ${frDate(week)}`} />
                        {label}
                      </label>
                    ))}
                  </div>
                </div>
                <label className="mt-2 block text-sm">
                  <span className="text-muted-foreground">Commentaire</span>
                  <textarea
                    className="mt-1 w-full rounded-md border border-border bg-background p-2 text-sm"
                    rows={2}
                    aria-label={`Commentaire — ${team.name}, semaine du ${frDate(week)}`}
                    value={s.comment}
                    onChange={(e) => patch(key, { comment: e.target.value })}
                  />
                </label>
              </fieldset>
            );
          }),
        )}
      </div>
      {mutation.isError ? <p className="mt-3 text-sm text-destructive">Envoi impossible pour le moment. Réessayez dans un instant.</p> : null}
      <Button className="mt-4 w-full" disabled={mutation.isPending || 0 === dirtyKeys.length} onClick={submit}>
        {mutation.isPending ? <Spinner className="size-4" /> : null}
        {0 === dirtyKeys.length ? "Aucune modification" : `Envoyer${dirtyKeys.length > 1 ? ` (${dirtyKeys.length} sections)` : ""}`}
      </Button>
    </AuthLayout>
  );
}
