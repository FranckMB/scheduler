import type { QueryClient, QueryKey } from "@tanstack/react-query";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useSyncExternalStore } from "react";

import { api } from "@/shared/api/client";

/**
 * FRT-04 — la consommation Mercure côté front, en UN seul endroit.
 *
 * Le backend publie l'avancement des générations sur `club:{clubId}:schedule:{id}`
 * (topics privés, SEC-05/06) ; jusqu'ici personne n'écoutait — le front pollait à
 * 2,5 s. Ce module ouvre UN EventSource par session, abonné au TEMPLATE du club
 * (`club:X:schedule:{id}` tel quel — le hub matche chaque topic exact contre lui,
 * délivrance prouvée) : toutes les générations du club arrivent sur la même
 * connexion, sans connaître leurs ids à l'avance.
 *
 * L'authentification est un cookie httpOnly posé par `GET /api/mercure/auth`
 * (path `/.well-known/mercure`, même origine via les proxys vite/nginx) : le JS
 * ne voit jamais le jeton hub. La réponse porte aussi `topicTemplate` — le front
 * ne connaît pas son clubId (tenant résolu serveur), c'est sa seule source.
 *
 * Mercure reste BEST-EFFORT (le publieur avale ses échecs) : à réception on
 * INVALIDE les caches react-query — le serveur reste la source de vérité, on ne
 * recopie jamais le payload dans le cache — et le polling ne meurt pas, il
 * ralentit (fallback) tant que le flux est connecté.
 */

const RETRY_MS = 10_000;

export type ScheduleStreamEvent = {
  scheduleId: string | null;
  status: string | null;
  /** COMPLETED / FAILED / failed / … — tout sauf les deux statuts en vol. */
  terminal: boolean;
};

/** Parse un événement du hub — `null` pour tout ce qui n'est pas un objet JSON. */
export function parseScheduleEvent(raw: string): ScheduleStreamEvent | null {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (null === parsed || "object" !== typeof parsed || Array.isArray(parsed)) {
    return null;
  }
  const record = parsed as Record<string, unknown>;
  const scheduleId = "string" === typeof record.scheduleId ? record.scheduleId : null;
  const status = "string" === typeof record.status ? record.status : null;

  return { scheduleId, status, terminal: null !== status && "PENDING" !== status && "GENERATING" !== status };
}

/**
 * Les caches à invalider pour un événement. Toujours la liste des plannings et le
 * statut suivi par le wizard ; un statut TERMINAL rend aussi périmés les créneaux
 * et diagnostics de CE planning (le résultat vient d'être importé). Sans
 * scheduleId (défense : vieux payload), on élargit au préfixe — trop large vaut
 * mieux qu'un écran figé.
 */
export function invalidationKeysFor(event: ScheduleStreamEvent): QueryKey[] {
  const keys: QueryKey[] = [
    ["schedules"],
    null !== event.scheduleId ? ["wizard", "schedule_status", event.scheduleId] : ["wizard", "schedule_status"],
  ];
  if (event.terminal) {
    keys.push(
      null !== event.scheduleId ? ["slots", event.scheduleId] : ["slots"],
      null !== event.scheduleId ? ["diagnostics", event.scheduleId] : ["diagnostics"],
    );
  }

  return keys;
}

// --- gestionnaire singleton (ref-compté : plusieurs écrans, une connexion) -----

let refs = 0;
let source: EventSource | null = null;
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let connected = false;
const connectedListeners = new Set<() => void>();

function setConnected(value: boolean): void {
  if (connected !== value) {
    connected = value;
    for (const listener of connectedListeners) {
      listener();
    }
  }
}

/** Lu par les `refetchInterval` : flux connecté → le polling passe en fallback lent. */
export function isScheduleStreamConnected(): boolean {
  return connected;
}

function subscribeConnected(listener: () => void): () => void {
  connectedListeners.add(listener);
  return () => connectedListeners.delete(listener);
}

function teardown(): void {
  if (null !== retryTimer) {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
  source?.close();
  source = null;
  setConnected(false);
}

function scheduleRetry(queryClient: QueryClient): void {
  if (null !== retryTimer) {
    return;
  }
  retryTimer = setTimeout(() => {
    retryTimer = null;
    if (refs > 0) {
      void open(queryClient);
    }
  }, RETRY_MS);
}

async function open(queryClient: QueryClient): Promise<void> {
  try {
    // D'abord le cookie (TTL 1 h) — CHAQUE (ré)ouverture ré-authentifie : c'est ce
    // qui rattrape un cookie expiré, EventSource seul rejouerait l'ancien à vie.
    const { topicTemplate } = await api.get("mercure/auth").json<{ topicTemplate: string }>();
    if (0 === refs || null !== source) {
      return; // relâché (ou rouvert) pendant l'aller-retour d'auth
    }
    const stream = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(topicTemplate)}`);
    source = stream;
    stream.onopen = () => setConnected(true);
    stream.onmessage = (event: MessageEvent<string>) => {
      const parsed = parseScheduleEvent(event.data);
      if (null !== parsed) {
        for (const queryKey of invalidationKeysFor(parsed)) {
          void queryClient.invalidateQueries({ queryKey });
        }
      }
    };
    // Erreur = on reprend la main (pas la reconnexion native : elle ne ré-authentifie
    // pas). Fermer déclenche le fallback polling à 2,5 s, puis on retentera.
    stream.onerror = () => {
      teardown();
      scheduleRetry(queryClient);
    };
  } catch {
    setConnected(false);
    scheduleRetry(queryClient);
  }
}

/** Prend une référence sur le flux (l'ouvre au premier preneur) ; rend le release. */
export function acquireScheduleStream(queryClient: QueryClient): () => void {
  refs += 1;
  if (1 === refs) {
    void open(queryClient);
  }
  let released = false;

  return () => {
    if (released) {
      return;
    }
    released = true;
    refs -= 1;
    if (0 === refs) {
      teardown();
    }
  };
}

/**
 * Tient le flux ouvert tant que `active` (une génération en vol quelque part) et
 * rend `connected` — les sites de polling s'en servent pour ralentir leur cadence.
 */
export function useScheduleStream(active: boolean): boolean {
  const queryClient = useQueryClient();
  useEffect(() => {
    if (!active) {
      return;
    }

    return acquireScheduleStream(queryClient);
  }, [active, queryClient]);

  return useSyncExternalStore(subscribeConnected, isScheduleStreamConnected, () => false);
}
