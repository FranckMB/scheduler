/**
 * P5-6 — mémoire d'un seul incident serveur (statut ≥ 500), écrite par le hook
 * `afterResponse` de `client.ts` et lue par la modale de signalement contextuel
 * pour joindre le `request_id` ré-émis par le backend (corrélation front↔logs, P5-11).
 *
 * Présentation pure, aucune règle métier : on retient le dernier incident et sa date,
 * et on ne le rend que tant qu'il est frais (< 10 min) — au-delà, il ne colle plus au
 * geste de l'utilisateur qui ouvre le signalement.
 */
const FRESHNESS_WINDOW_MS = 10 * 60 * 1000;

let lastIncident: { requestId: string; at: number } | null = null;

export function recordIncident(requestId: string, at: number = Date.now()): void {
  lastIncident = { requestId, at };
}

/** Le request-id du dernier incident s'il date de moins de 10 min, sinon null. */
export function readRecentIncidentRequestId(now: number = Date.now()): string | null {
  if (null === lastIncident) {
    return null;
  }
  if (now - lastIncident.at >= FRESHNESS_WINDOW_MS) {
    return null;
  }
  return lastIncident.requestId;
}

export function clearLastIncident(): void {
  lastIncident = null;
}
