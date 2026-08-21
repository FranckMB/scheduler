import { useMutationState, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, CloudOff, Loader2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { useOnline } from "@/shared/lib/online";

/**
 * P5-14 — LE BANDEAU HORS-LIGNE (PR-1). Il vit DANS LE FLUX, tout en haut du shell (`RootShell`),
 * AVANT le contenu de page : il empile, il ne recouvre pas. Aucun overlay, aucun z-index. Monté une
 * seule fois (RootShell est persistant sur toutes les routes, page publique de doléances comprise —
 * le coach dans un gymnase sans réseau est le cas NOMINAL), donc il ne se re-monte pas d'une route à
 * l'autre : pas de ré-animation ni de ré-annonce à chaque navigation.
 *
 * ══ Ce qui, dans la maquette, n'est PAS pris au pied de la lettre (« la vérité, c'est le code ») ══
 *  1. « Envoyer maintenant » DÈS qu'il y a des modifications en attente → seulement EN LIGNE.
 *     Hors ligne, `queryClient.resumePausedMutations()` est un NO-OP (query-core `queryClient.js:206`,
 *     `if (onlineManager.isOnline())`). Un bouton hors ligne ne ferait littéralement RIEN — on le retire.
 *  2. « Rien n'est perdu, tout part dès que le réseau revient » → « …gardez cet onglet ouvert ». La
 *     file de mutations vit EN MÉMOIRE (`shared/lib/queryClient.ts` n'a pas de bloc `mutations`
 *     persistées) : un rechargement la perd. La persistance est un LOT SÉPARÉ, pas embarquée ici.
 *  3. « Reconnexion » (état 3) → « envoi de vos modifications en cours ». Aucun signal « reconnexion »
 *     n'existe ; ce qui existe est la REPRISE AUTOMATIQUE au retour du réseau (`queryClient.js:42-44`).
 *  4. « Inséré sous le header dans le même flux collant » → au-dessus de TOUT, dans le flux de
 *     `RootShell` : le header n'est pas sticky (`AppLayout.tsx`) et n'existe pas sur les pages publiques.
 *
 * ⚠ Ne DOUBLE pas `app/OfflineScreen.tsx` (la page PLEINE, servie quand il n'y a RIEN derrière : chunk
 * ou /api/me qui échoue au boot). Les deux coexistent sans se contredire.
 *
 * Passe de design `ui-ux-pro-max` (avant le 1er RED) : pas de rouge (hors ligne = attendu, données
 * saines) — neutre (état 1) → ambre `warning` (états 2/3) → vert `success` (état 4) ; la COULEUR ne
 * porte JAMAIS seule le sens (icône + phrase distinctes) ; tokens `text-warning`/`text-success`
 * validés AA clair+sombre par `a11y-contrast.spec.ts`. Compteur en `tabular-nums` (pas de gigue de
 * largeur). Bouton « Envoyer maintenant » en emphase BASSE (filet rare, pas le chemin heureux).
 */

type Mode = "offline" | "flushing" | "confirm";

const CONFIRM_MS = 5_000; // l'état 4 s'efface seul après 5 s
const OFFLINE_SENTENCE = "Vous êtes hors ligne. Vos données restent consultables.";

function pendingSentence(n: number): string {
  return `${n} modification${n > 1 ? "s" : ""} en attente. Elles partiront au retour du réseau — gardez cet onglet ouvert.`;
}

// On ne REVENDIQUE l'envoi que si des gestes sont réellement partis SANS échec. Sinon (rien à
// envoyer, ou une reprise a échoué et le filet global l'a déjà toastée) : « De retour en ligne. »
// — sans mentir, sans doubler le toast. Fonction PURE (hors composant) : les valeurs de refs lui
// sont passées depuis un effet, jamais lues pendant le rendu (lint `react-hooks/refs`).
function confirmSentence(hadWork: boolean, succeeded: boolean, failed: boolean): string {
  return hadWork && succeeded && !failed ? "De retour en ligne. Vos modifications sont parties." : "De retour en ligne.";
}

export function OfflineBanner() {
  const online = useOnline();
  const client = useQueryClient();

  // Compteur RÉEL des gestes en attente = mutations EN PAUSE (`isPaused`), jamais une estimation.
  // `networkMode: "online"` (défaut) → hors ligne, une mutation ne démarre pas et part `isPaused`.
  const mutations = useMutationState({
    select: (m) => ({ id: m.mutationId, status: m.state.status, isPaused: m.state.isPaused }),
  });
  const pausedCount = mutations.filter((m) => m.isPaused).length;

  const everPaused = useRef<Set<number>>(new Set());
  const hadWork = useRef(false); // au moins un geste a été garé pendant la coupure
  const anyFailed = useRef(false); // une reprise a échoué → le filet global l'a déjà toastée
  const anySucceeded = useRef(false); // au moins une reprise a réellement abouti

  // Suivi des gestes garés + de leur issue (sert la phrase de confirmation). Effet idempotent qui
  // n'écrit que des refs → aucune boucle de rendu.
  useEffect(() => {
    for (const m of mutations) {
      if (m.isPaused) {
        everPaused.current.add(m.id);
        hadWork.current = true;
      }
      if (everPaused.current.has(m.id)) {
        if ("error" === m.status) anyFailed.current = true;
        if ("success" === m.status) anySucceeded.current = true;
      }
    }
  }, [mutations]);

  // La PHASE en ligne (reprise / confirmation) porte une mémoire à minuterie. Le cas HORS LIGNE, lui,
  // se DÉRIVE au rendu (`mode` ci-dessous) : pas de state à synchroniser pour lui.
  const [phase, setPhase] = useState<"flushing" | "confirm" | null>(null);
  // Phrase de confirmation FIGÉE à l'entrée dans « confirm » (state, jamais une ref lue au rendu).
  const [confirmText, setConfirmText] = useState("De retour en ligne.");
  const mode: Mode | null = online ? phase : "offline";

  // Transition réseau — edge-triggered sur un STORE EXTERNE (onlineManager, via useOnline). Non
  // dérivable au rendu : le retour du réseau doit BASCULER la phase (reprise → confirmation) avec sa
  // minuterie. D'où le `set-state-in-effect` assumé (patron du dépôt : GenerateStep, VenuesStep…).
  const prevOnline = useRef(online);
  useEffect(() => {
    const was = prevOnline.current;
    prevOnline.current = online;
    if (online === was) {
      return; // couvre le tout premier rendu (aucune transition)
    }
    /* eslint-disable react-hooks/set-state-in-effect -- transition edge-triggered sur onlineManager, non dérivable au rendu (phase en ligne à minuterie) */
    if (!online) {
      setPhase(null); // `mode` dérive déjà "offline" ; on repart d'une phase propre au prochain retour
      return;
    }
    // Le réseau REVIENT : « envoi en cours » s'il y avait des gestes garés, sinon confirmation brève
    // (fermeture symétrique du hors-ligne). `hadWork` est un REF : valeur courante sans re-déclencher.
    if (!hadWork.current) {
      setConfirmText(confirmSentence(hadWork.current, anySucceeded.current, anyFailed.current));
    }
    setPhase(hadWork.current ? "flushing" : "confirm");
    /* eslint-enable react-hooks/set-state-in-effect */
  }, [online]);

  // Fin de la reprise : plus rien en pause NI en vol → on confirme. `resuming` (gestes garés
  // autrefois, qui courent maintenant) est calculé ICI, dans l'effet — jamais au rendu (lint refs).
  useEffect(() => {
    if ("flushing" !== phase) {
      return;
    }
    const resuming = mutations.filter((m) => everPaused.current.has(m.id) && "pending" === m.status && !m.isPaused).length;
    if (0 === pausedCount && 0 === resuming) {
      setConfirmText(confirmSentence(hadWork.current, anySucceeded.current, anyFailed.current));
      setPhase("confirm");
    }
  }, [phase, pausedCount, mutations]);

  // La confirmation s'efface seule après 5 s ; on remet les compteurs à zéro pour la coupure suivante.
  // (setState dans un timeout = asynchrone, hors du champ de `set-state-in-effect`.)
  useEffect(() => {
    if ("confirm" !== mode) {
      return;
    }
    const t = setTimeout(() => {
      setPhase(null);
      setConfirmText("De retour en ligne.");
      hadWork.current = false;
      anyFailed.current = false;
      anySucceeded.current = false;
      everPaused.current.clear();
    }, CONFIRM_MS);
    return () => clearTimeout(t);
  }, [mode]);

  // La région live ne reçoit QUE les phrases de TRANSITION (hors ligne / retour), jamais le compteur :
  // un nombre qui monte dans une région live martèle le lecteur d'écran (patron maison AUD-FRT-23/24).
  const announcement = "offline" === mode ? OFFLINE_SENTENCE : "confirm" === mode ? confirmText : "";

  const visible = null !== mode;

  return (
    <>
      {/* Collapse `grid-rows` 0fr↔1fr + fondu : ouvre/ferme SANS connaître la hauteur d'avance, le
          contenu glisse au lieu de sauter. Coupé sous `prefers-reduced-motion`. In-flow : pas
          d'overlay, pas de z-index. */}
      <div className={`grid overflow-hidden transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none ${visible ? "grid-rows-[1fr]" : "grid-rows-[0fr]"}`}>
        <div className="min-h-0">
          {null !== mode ? (
            <BannerRow mode={mode} pausedCount={pausedCount} confirmText={confirmText} onResume={() => void client.resumePausedMutations()} />
          ) : null}
        </div>
      </div>
      {/* sr-only, role=status polite : les transitions seulement (voir plus haut). Pas de vol de
          focus, jamais `alert`. La confirmation reste dans l'historique d'annonces même après
          l'effacement visuel à 5 s. */}
      <p role="status" aria-live="polite" aria-atomic="true" className="sr-only">
        {announcement}
      </p>
    </>
  );
}

function BannerRow({ mode, pausedCount, confirmText, onResume }: { mode: Mode; pausedCount: number; confirmText: string; onResume: () => void }) {
  // Choix d'icône / couleur / phrase = PRÉSENTATION (libellé), pas une décision de comportement.
  const showNet = "flushing" === mode && pausedCount > 0; // le filet « Envoyer maintenant » : EN LIGNE et s'il RESTE des pauses

  let icon = <CloudOff aria-hidden className="size-4 shrink-0 text-muted-foreground" />;
  let message = OFFLINE_SENTENCE;

  if ("offline" === mode && pausedCount > 0) {
    icon = <CloudOff aria-hidden className="size-4 shrink-0 text-warning" />;
    message = pendingSentence(pausedCount);
  } else if ("flushing" === mode) {
    icon = <Loader2 aria-hidden className="size-4 shrink-0 animate-spin text-warning motion-reduce:animate-none" />;
    message = "De retour en ligne — envoi de vos modifications en cours…";
  } else if ("confirm" === mode) {
    icon = <CheckCircle2 aria-hidden className="size-4 shrink-0 text-success" />;
    message = confirmText;
  }

  return (
    <div data-testid="offline-banner" className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-card px-4 py-2.5 text-sm text-foreground">
      {icon}
      {/* `tabular-nums` : le compteur qui monte ne fait pas gigoter la largeur du bandeau. */}
      <span className="tabular-nums">{message}</span>
      {showNet ? (
        <button
          type="button"
          onClick={onResume}
          className="ml-auto inline-flex min-h-11 items-center justify-center rounded-md border border-border bg-transparent px-3 text-sm font-medium text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        >
          Envoyer maintenant
        </button>
      ) : null}
    </div>
  );
}
