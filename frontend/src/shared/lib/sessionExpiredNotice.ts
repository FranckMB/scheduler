/**
 * P5-14 — marqueur one-shot « la session vient d'expirer ».
 *
 * Posé par `client.ts` juste avant la redirection vers /login qu'un 401 déclenche
 * (session périmée par inactivité), lu ET consommé par `LoginPage` au montage pour
 * afficher le bloc de réassurance au-dessus du formulaire.
 *
 * ⚠ La clé est NEUTRE, sans nom de marque : l'ancienne clé de brouillon de vœux portait
 * l'ancien nom produit et a été piégée par un renommage (nettoyée le 2026-08-21, #683). Un
 * nom produit dans une clé de stockage rouvre la chasse aux littéraux à chaque renommage —
 * et la garde `product.guard.test.ts` rougirait dessus (elle vient d'ailleurs de m'attraper).
 *
 * ⚠ PAS de query param (`?session=expiree`) : une URL partagée ou mise en favori
 * afficherait le message à tort. Le marqueur vit en `sessionStorage` (meurt avec
 * l'onglet), il ne voyage jamais dans l'URL.
 */
const SESSION_EXPIRED_KEY = "session-expired-notice";

/** Le stockage peut jeter (mode privé strict, quota) — on ne casse jamais le rail d'auth pour ça. */
function storage(): Storage | null {
  try {
    if ("undefined" === typeof window || !window.sessionStorage) {
      return null;
    }
    return window.sessionStorage;
  } catch {
    return null;
  }
}

/** Note qu'une session vient d'expirer (appelé par le hook 401 avant la redirection). */
export function markSessionExpired(): void {
  try {
    storage()?.setItem(SESSION_EXPIRED_KEY, "1");
  } catch {
    // Stockage indisponible : le bloc de réassurance ne s'affichera pas, l'app reste saine.
  }
}

/**
 * Lit le marqueur SANS le retirer — idempotent, donc sûr dans un initialiseur de
 * `useState` (StrictMode double-invoque l'initialiseur : une lecture pure renvoie la
 * même valeur deux fois, un `consume` mangerait le marqueur au premier passage).
 */
export function peekSessionExpired(): boolean {
  const store = storage();
  if (null === store) {
    return false;
  }
  try {
    return "1" === store.getItem(SESSION_EXPIRED_KEY);
  } catch {
    return false;
  }
}

/** Retire le marqueur (idempotent) — appelé dans un effet APRÈS l'avoir affiché. */
export function clearSessionExpired(): void {
  try {
    storage()?.removeItem(SESSION_EXPIRED_KEY);
  } catch {
    // Rien à nettoyer si le stockage est indisponible.
  }
}

/**
 * Lit le marqueur ET le retire (one-shot). Pratique pour les consommateurs hors
 * rendu (tests, code impératif) ; en rendu React, préférer `peekSessionExpired`
 * (initialiseur) + `clearSessionExpired` (effet), pour rester pur sous StrictMode.
 */
export function consumeSessionExpired(): boolean {
  const present = peekSessionExpired();
  if (present) {
    clearSessionExpired();
  }
  return present;
}
