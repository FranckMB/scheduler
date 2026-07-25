import { useMemo, useState } from "react";
import { Check, Copy } from "lucide-react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { periodAdjustWeeks } from "@/features/cockpit/lib/date";
import { useUpdateCoach, useWizardTeamCoaches, useWizardTeams } from "@/features/wizard/queries";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { copyToClipboard } from "@/shared/lib/clipboard";

import { doleancesLink, type CampaignCoach, type CoachWishCampaign } from "./campaignApi";
import { useCreateCoachWishCampaign, useUpdateCoachWishCampaign } from "./campaignQueries";

interface CampaignDialogProps {
  /** Entrée MÈRE des vacances à laquelle la campagne s'ancre. */
  entry: CalendarEntry;
  /** Fenêtre de la saison — borne les semaines proposées. */
  season: { startDate: string; endDate: string } | null;
  /** Campagne déjà créée pour cette période, ou null (création). */
  existing: CoachWishCampaign | null;
  onClose: () => void;
}

const frDate = (iso: string): string => {
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
};

/**
 * « Solliciter les coachs » (feature #10, lot C2) : crée/modifie la campagne de collecte
 * d'une période de vacances (semaines, équipes, deadline), puis liste les coachs avec leur
 * lien personnel à copier (WhatsApp — pas d'email en C2). L'email est éditable ici en
 * préparation de C3 (envoi) : aucun effet aujourd'hui.
 */
export function CampaignDialog({ entry, season, existing, onClose }: CampaignDialogProps) {
  const teamsQuery = useWizardTeams();
  const teamCoachesQuery = useWizardTeamCoaches();
  const createCampaign = useCreateCoachWishCampaign();
  const updateCampaign = useUpdateCoachWishCampaign();

  // Campagne courante (après enregistrement, on garde la réponse pour afficher les liens).
  const [campaign, setCampaign] = useState<CoachWishCampaign | null>(existing);

  const availableWeeks = useMemo(() => (null === season ? [] : periodAdjustWeeks(entry.startDate, entry.endDate, season, entry.periodType)), [entry, season]);

  // Équipes ayant AU MOINS un coach — cocher une équipe sans coach ne crée aucun lien.
  const coachCountByTeam = useMemo(() => {
    const map = new Map<string, number>();
    for (const tc of teamCoachesQuery.data ?? []) {
      map.set(tc.teamId, (map.get(tc.teamId) ?? 0) + 1);
    }
    return map;
  }, [teamCoachesQuery.data]);
  const teams = (teamsQuery.data ?? []).filter((t) => t.isActive && (coachCountByTeam.get(t.id) ?? 0) > 0);

  const [weeks, setWeeks] = useState<Set<string>>(() => new Set(existing ? existing.weeks : availableWeeks.map((w) => w.monday)));
  const [teamIds, setTeamIds] = useState<Set<string>>(() => new Set(existing ? existing.teamIds : []));
  const [deadline, setDeadline] = useState<string>(existing?.deadline ?? entry.startDate);

  const toggle = (set: Set<string>, value: string, apply: (next: Set<string>) => void) => {
    const next = new Set(set);
    if (next.has(value)) {
      next.delete(value);
    } else {
      next.add(value);
    }
    apply(next);
  };

  const canSave = weeks.size > 0 && teamIds.size > 0 && "" !== deadline;
  const saving = createCampaign.isPending || updateCampaign.isPending;

  const save = () => {
    const body = { calendarEntryId: entry.id, deadline, weeks: [...weeks].sort(), teamIds: [...teamIds] };
    if (null !== campaign) {
      updateCampaign.mutate({ id: campaign.id, body }, { onSuccess: setCampaign });
    } else {
      createCampaign.mutate(body, { onSuccess: setCampaign });
    }
  };

  const failed = createCampaign.isError || updateCampaign.isError;

  return (
    <Modal label="Solliciter les coachs" title="Solliciter les coachs" onClose={onClose} className="max-w-lg">
      <p className="mt-2 text-sm text-muted-foreground">Choisissez les semaines et les équipes concernées, puis copiez le lien de chaque coach pour le lui envoyer.</p>

      <fieldset className="mt-4">
        <legend className="text-sm font-medium">Semaines</legend>
        <div className="mt-1 space-y-1">
          {0 === availableWeeks.length ? (
            <p className="text-sm text-muted-foreground">Aucune semaine disponible sur cette période.</p>
          ) : (
            availableWeeks.map((w) => (
              <label key={w.monday} className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={weeks.has(w.monday)} onChange={() => toggle(weeks, w.monday, setWeeks)} aria-label={`Semaine du ${frDate(w.startDate)}`} />
                Semaine du {frDate(w.startDate)} au {frDate(w.endDate)}
              </label>
            ))
          )}
        </div>
      </fieldset>

      <fieldset className="mt-4">
        <legend className="text-sm font-medium">Équipes</legend>
        <div className="mt-1 space-y-1">
          {0 === teams.length ? (
            <p className="text-sm text-muted-foreground">Aucune équipe avec un coach rattaché.</p>
          ) : (
            teams.map((t) => (
              <label key={t.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={teamIds.has(t.id)} onChange={() => toggle(teamIds, t.id, setTeamIds)} aria-label={t.name} />
                {t.name}
              </label>
            ))
          )}
        </div>
      </fieldset>

      <label className="mt-4 flex items-center gap-2 text-sm font-medium">
        À renvoyer avant le
        <Input type="date" className="h-8 w-40" value={deadline} onChange={(e) => setDeadline(e.target.value)} aria-label="Date limite" />
      </label>

      {failed ? <p className="mt-3 text-sm text-destructive">Enregistrement impossible. Vérifiez les semaines et équipes choisies.</p> : null}

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onClose}>
          Fermer
        </Button>
        <Button size="sm" disabled={!canSave || saving} onClick={save}>
          {saving ? <Spinner className="size-4" /> : null}
          {null === campaign ? "Créer la collecte" : "Enregistrer"}
        </Button>
      </div>

      {null !== campaign ? <CoachLinks campaign={campaign} onEmailSaved={(coachId, email) => setCampaign((c) => (null === c ? c : { ...c, coaches: c.coaches.map((k) => (k.coachId === coachId ? { ...k, email } : k)) }))} /> : null}
    </Modal>
  );
}

function CoachLinks({ campaign, onEmailSaved }: { campaign: CoachWishCampaign; onEmailSaved: (coachId: string, email: string) => void }) {
  return (
    <div className="mt-5 border-t border-border pt-4">
      <p className="text-sm font-medium">
        Liens des coachs · {campaign.respondedCoachCount}/{campaign.totalCoachCount} ont répondu
      </p>
      {0 === campaign.coaches.length ? (
        <p className="mt-1 text-sm text-muted-foreground">Aucun coach sur le périmètre choisi.</p>
      ) : (
        <ul className="mt-2 space-y-2">
          {campaign.coaches.map((coach) => (
            <CoachRow key={coach.coachId} coach={coach} onEmailSaved={onEmailSaved} />
          ))}
        </ul>
      )}
    </div>
  );
}

function CoachRow({ coach, onEmailSaved }: { coach: CampaignCoach; onEmailSaved: (coachId: string, email: string) => void }) {
  const [copied, setCopied] = useState(false);
  const [email, setEmail] = useState(coach.email ?? "");
  const updateCoach = useUpdateCoach();

  const copy = async () => {
    if (await copyToClipboard(doleancesLink(coach.token))) {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }
  };

  const saveEmail = () => {
    const trimmed = email.trim();
    if ("" === trimmed || trimmed === (coach.email ?? "")) {
      return;
    }
    updateCoach.mutate({ id: coach.coachId, body: { firstName: coach.firstName, lastName: coach.lastName, email: trimmed } }, { onSuccess: () => onEmailSaved(coach.coachId, trimmed) });
  };

  return (
    <li className="rounded-md border border-border p-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">
          {coach.firstName} {coach.lastName}
        </span>
        {null !== coach.respondedAt ? <span className="rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent">✓ répondu le {frDate(coach.respondedAt.slice(0, 10))}</span> : null}
        <Button variant="outline" size="sm" className="ml-auto" onClick={copy}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          {copied ? "Copié" : "Copier le lien"}
        </Button>
      </div>
      <div className="mt-1.5 flex items-center gap-2">
        <Input type="email" placeholder="email (optionnel, pour l'envoi automatique à venir)" className="h-8 flex-1 text-xs" value={email} onChange={(e) => setEmail(e.target.value)} onBlur={saveEmail} aria-label={`Email de ${coach.firstName} ${coach.lastName}`} />
      </div>
    </li>
  );
}
