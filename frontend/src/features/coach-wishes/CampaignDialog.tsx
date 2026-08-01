import { useMemo, useState } from "react";
import { HTTPError } from "ky";
import { Check, Copy, Send } from "lucide-react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { isActionableWeek, periodAdjustWeeks, todayISO } from "@/features/cockpit/lib/date";
import { useUpdateCoach, useWizardTeamCoaches, useWizardTeams } from "@/features/wizard/queries";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { copyToClipboard } from "@/shared/lib/clipboard";

import { doleancesLink, type CampaignCoach, type CoachWishCampaign } from "./campaignApi";
import { useCreateCoachWishCampaign, useRemindCampaignSilent, useSendCampaignLinks, useUpdateCoachWishCampaign } from "./campaignQueries";

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

  // P3-13/P3-15 (c) — on ne sollicite un coach que pour ce qu'il reste à vivre : les
  // semaines RÉVOLUES étaient proposées ET cochées par défaut. Même règle et même foyer
  // que le radar (`isActionableWeek`), pas une seconde implémentation (CLAUDE.md §7.2).
  // ⚠ Une semaine ENTAMÉE reste offerte (revue #344) : une vacance qui démarre un samedi
  // n'aurait plus pu faire l'objet d'aucune collecte dès le lundi suivant, pour des
  // séances pourtant toutes à venir — et rien d'autre dans l'app ne crée une campagne.
  //
  // ⚠ Une campagne EXISTANTE peut porter une semaine devenue révolue : elle reste LISTÉE
  // et marquée, jamais offerte à une nouvelle sélection. La masquer laisserait `weeks`
  // porter un lundi invisible que `save()` renverrait — un état que l'écran ne montre pas
  // (CHOISIR n'offre que l'avenir, NOMMER garde le reste lisible).
  const today = todayISO();
  const availableWeeks = useMemo(() => {
    if (null === season) {
      return [];
    }
    const all = periodAdjustWeeks(entry.startDate, entry.endDate, season, entry.periodType);
    const kept = new Set(existing?.weeks ?? []);

    return all.filter((w) => isActionableWeek(w, today) || kept.has(w.monday));
  }, [entry, season, existing, today]);

  // Équipes ayant AU MOINS un coach — cocher une équipe sans coach ne crée aucun lien.
  const coachCountByTeam = useMemo(() => {
    const map = new Map<string, number>();
    for (const tc of teamCoachesQuery.data ?? []) {
      map.set(tc.teamId, (map.get(tc.teamId) ?? 0) + 1);
    }
    return map;
  }, [teamCoachesQuery.data]);
  const teams = (teamsQuery.data ?? []).filter((t) => t.isActive && (coachCountByTeam.get(t.id) ?? 0) > 0);

  // Défaut : les semaines À VENIR seulement — cocher d'office une semaine révolue partait
  // solliciter les coachs pour du passé.
  const [weeks, setWeeks] = useState<Set<string>>(() => new Set(existing ? existing.weeks : availableWeeks.filter((w) => isActionableWeek(w, today)).map((w) => w.monday)));
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
                {/* Le marqueur vit DANS le nom accessible : `aria-label` écrase le
                    contenu du label, donc un marqueur posé à côté n'est jamais annoncé et
                    un lecteur d'écran entend des semaines indistinctes (revue #344). */}
                <input
                  type="checkbox"
                  className="size-4 accent-[var(--accent)]"
                  checked={weeks.has(w.monday)}
                  onChange={() => toggle(weeks, w.monday, setWeeks)}
                  aria-label={`Semaine du ${frDate(w.startDate)}${isActionableWeek(w, today) ? "" : " (révolue)"}`}
                />
                Semaine du {frDate(w.startDate)} au {frDate(w.endDate)}
                {isActionableWeek(w, today) ? null : <span className="text-xs italic text-muted-foreground">révolue</span>}
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

      {null !== campaign ? (
        <CoachLinks
          campaign={campaign}
          onEmailSaved={(coachId, email) => setCampaign((c) => (null === c ? c : { ...c, coaches: c.coaches.map((k) => (k.coachId === coachId ? { ...k, email } : k)) }))}
          onCampaignRefreshed={setCampaign}
        />
      ) : null}
    </Modal>
  );
}

/** Jour calendaire Europe/Paris d'une date (YYYY-MM-DD) — le back throttle sur CE fuseau. */
const parisDay = (d: Date): string => new Intl.DateTimeFormat("fr-CA", { timeZone: "Europe/Paris", year: "numeric", month: "2-digit", day: "2-digit" }).format(d);

/** lastReminderAt (ISO) est-il le MÊME jour Europe/Paris que maintenant ? (parité back, D3). */
const isSameParisDay = (iso: string | null): boolean => null !== iso && parisDay(new Date(iso)) === parisDay(new Date());

/** Message d'erreur d'une action d'envoi : 409 = saison close (jamais réessayable), sinon défaut. */
const errorMessage = (error: unknown, fallback: string): string => (error instanceof HTTPError && 409 === error.response.status ? "Cette saison est archivée — la collecte est close." : fallback);

type CoachStatus = "responded" | "pending" | "no-email";

/**
 * Statut mutuellement exclusif d'un coach (D2). « Répondu » PRIME : un coach répond souvent
 * via WhatsApp SANS email (respondedAt posé, email null) — il reste « répondu », jamais rangé
 * en « pas d'email » (sinon le filtre le cacherait alors que le badge le compte répondant).
 */
const coachStatus = (c: CampaignCoach): CoachStatus => (null !== c.respondedAt ? "responded" : null === c.email || "" === c.email ? "no-email" : "pending");

const STATUS_LABELS: { key: CoachStatus; label: string }[] = [
  { key: "responded", label: "Répondu" },
  { key: "pending", label: "En attente" },
  { key: "no-email", label: "Pas d'email" },
];

function CoachLinks({ campaign, onEmailSaved, onCampaignRefreshed }: { campaign: CoachWishCampaign; onEmailSaved: (coachId: string, email: string) => void; onCampaignRefreshed: (c: CoachWishCampaign) => void }) {
  const sendLinks = useSendCampaignLinks();
  const remind = useRemindCampaignSilent();
  const teamsQuery = useWizardTeams();
  const teamCoachesQuery = useWizardTeamCoaches();

  // Filtres (D1/D2/D3) : par ÉQUIPE (celles de la campagne) et par STATUT, additifs dans
  // chaque axe, combinés en ET entre axes. N'affectent QUE la liste, jamais les boutons (D4).
  const [teamFilter, setTeamFilter] = useState<Set<string>>(new Set());
  const [statusFilter, setStatusFilter] = useState<Set<CoachStatus>>(new Set());

  // Équipes de la campagne (id + nom) pour les boutons de filtre.
  const campaignTeamIds = new Set(campaign.teamIds);
  const campaignTeams = (teamsQuery.data ?? []).filter((t) => campaignTeamIds.has(t.id));
  // coachId → équipes de la campagne qu'il coache (D1 : TeamCoach ∩ teamIds campagne).
  const coachTeams = new Map<string, Set<string>>();
  for (const tc of teamCoachesQuery.data ?? []) {
    if (campaignTeamIds.has(tc.teamId)) {
      coachTeams.set(tc.coachId, (coachTeams.get(tc.coachId) ?? new Set()).add(tc.teamId));
    }
  }

  const toggle = <T,>(set: Set<T>, value: T, apply: (n: Set<T>) => void) => {
    const next = new Set(set);
    if (next.has(value)) {
      next.delete(value);
    } else {
      next.add(value);
    }
    apply(next);
  };

  const visibleCoaches = campaign.coaches.filter((c) => {
    const okTeam = 0 === teamFilter.size || [...(coachTeams.get(c.coachId) ?? new Set<string>())].some((t) => teamFilter.has(t));
    const okStatus = 0 === statusFilter.size || statusFilter.has(coachStatus(c));
    return okTeam && okStatus;
  });

  // Envoi global : les coachs à email PAS ENCORE servis (le bouton n'est pas un renvoi — D2).
  const unsentWithEmail = campaign.coaches.filter((c) => null !== c.email && "" !== c.email && null === c.sentAt);
  // Relance : silencieux à email — et une seule fois par jour (D3).
  const silentWithEmail = campaign.coaches.filter((c) => null !== c.email && "" !== c.email && null === c.respondedAt);
  const remindedToday = isSameParisDay(campaign.lastReminderAt);

  return (
    <div className="mt-5 border-t border-border pt-4">
      <p className="text-sm font-medium">
        Liens des coachs · {campaign.respondedCoachCount}/{campaign.totalCoachCount} ont répondu
      </p>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={0 === unsentWithEmail.length || sendLinks.isPending}
          title={0 === unsentWithEmail.length ? "Tous les coachs avec un email ont déjà reçu leur lien" : undefined}
          onClick={() => sendLinks.mutate({ id: campaign.id }, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}
        >
          {sendLinks.isPending ? <Spinner className="size-4" /> : <Send className="size-4" />}
          Envoyer les liens par email
        </Button>
        <Button
          variant="ghost"
          size="sm"
          disabled={remindedToday || 0 === silentWithEmail.length || remind.isPending}
          title={remindedToday ? "Déjà relancés aujourd'hui — pas deux fois le même jour" : 0 === silentWithEmail.length ? "Aucun coach silencieux avec un email" : undefined}
          onClick={() => remind.mutate(campaign.id, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}
        >
          {remind.isPending ? <Spinner className="size-4" /> : null}
          Relancer les silencieux
        </Button>
      </div>
      {/* Sans ceci un 422/409 resterait muet ; on distingue « saison close » (409) de « déjà
          relancé aujourd'hui » (422 — cache périmé, autre onglet) pour ne pas mal expliquer. */}
      {sendLinks.isError ? <p className="mt-2 text-sm text-destructive">{errorMessage(sendLinks.error, "Envoi impossible pour le moment. Réessayez.")}</p> : null}
      {remind.isError ? <p className="mt-2 text-sm text-destructive">{errorMessage(remind.error, "Relance impossible — les coachs ont peut-être déjà été relancés aujourd'hui.")}</p> : null}

      {/* Filtres — n'affectent QUE la liste ci-dessous (les boutons gardent leur périmètre, D4). */}
      {campaign.coaches.length > 0 ? (
        <div className="mt-3 space-y-1.5">
          {campaignTeams.length > 1 ? (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-xs text-muted-foreground">Équipe&nbsp;:</span>
              {campaignTeams.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  aria-pressed={teamFilter.has(t.id)}
                  className={`rounded-full border px-2 py-0.5 text-xs ${teamFilter.has(t.id) ? "border-accent bg-accent/15 text-accent" : "border-border text-muted-foreground"}`}
                  onClick={() => toggle(teamFilter, t.id, setTeamFilter)}
                >
                  {t.name}
                </button>
              ))}
            </div>
          ) : null}
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-xs text-muted-foreground">Statut&nbsp;:</span>
            {STATUS_LABELS.map((s) => (
              <button
                key={s.key}
                type="button"
                aria-pressed={statusFilter.has(s.key)}
                className={`rounded-full border px-2 py-0.5 text-xs ${statusFilter.has(s.key) ? "border-accent bg-accent/15 text-accent" : "border-border text-muted-foreground"}`}
                onClick={() => toggle(statusFilter, s.key, setStatusFilter)}
              >
                {s.label}
              </button>
            ))}
          </div>
        </div>
      ) : null}

      {0 === campaign.coaches.length ? (
        <p className="mt-1 text-sm text-muted-foreground">Aucun coach sur le périmètre choisi.</p>
      ) : 0 === visibleCoaches.length ? (
        <p className="mt-2 text-sm text-muted-foreground">Aucun coach pour ce filtre.</p>
      ) : (
        <ul className="mt-2 space-y-2">
          {visibleCoaches.map((coach) => (
            <CoachRow key={coach.coachId} coach={coach} campaignId={campaign.id} onEmailSaved={onEmailSaved} onCampaignRefreshed={onCampaignRefreshed} />
          ))}
        </ul>
      )}
    </div>
  );
}

function CoachRow({ coach, campaignId, onEmailSaved, onCampaignRefreshed }: { coach: CampaignCoach; campaignId: string; onEmailSaved: (coachId: string, email: string) => void; onCampaignRefreshed: (c: CoachWishCampaign) => void }) {
  const [copied, setCopied] = useState(false);
  const [email, setEmail] = useState(coach.email ?? "");
  const updateCoach = useUpdateCoach();
  const sendLinks = useSendCampaignLinks();

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

  const hasEmail = null !== coach.email && "" !== coach.email;

  return (
    <li className="rounded-md border border-border p-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">
          {coach.firstName} {coach.lastName}
        </span>
        {null !== coach.respondedAt ? <span className="rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent">✓ répondu le {frDate(coach.respondedAt.slice(0, 10))}</span> : null}
        {hasEmail ? null : <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">pas d'email</span>}
        {null !== coach.sentAt ? <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">envoyé le {frDate(coach.sentAt.slice(0, 10))}</span> : null}
        {hasEmail ? (
          // Envoi CIBLÉ (ajout tardif d'un email, ou renvoi volontaire à CE coach — D1).
          <Button variant="ghost" size="sm" disabled={sendLinks.isPending} onClick={() => sendLinks.mutate({ id: campaignId, coachIds: [coach.coachId] }, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}>
            <Send className="size-4" />
            {null === coach.sentAt ? "Envoyer" : "Renvoyer"}
          </Button>
        ) : null}
        <Button variant="outline" size="sm" className="ml-auto" onClick={copy}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          {copied ? "Copié" : "Copier le lien"}
        </Button>
      </div>
      <div className="mt-1.5 flex items-center gap-2">
        <Input type="email" placeholder="email (pour l'envoi du lien)" className="h-8 flex-1 text-xs" value={email} onChange={(e) => setEmail(e.target.value)} onBlur={saveEmail} aria-label={`Email de ${coach.firstName} ${coach.lastName}`} />
      </div>
    </li>
  );
}
