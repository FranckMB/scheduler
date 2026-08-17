import { useEffect, useState } from "react";

import "./GenerationWaiting.css";

const PHRASES = [
  "Placement des équipes prioritaires…",
  "Respect des disponibilités des gymnases…",
  "Vérification des créneaux des coachs…",
  "Application de vos contraintes…",
  "Optimisation de la répartition sur la semaine…",
  "Recherche du meilleur planning possible…",
];

/**
 * Écran d'attente affiché pendant une génération (premier run et régénérations).
 *
 * Scène du design fondateur (boucle 8 s : terrain en filigrane, grille de créneaux
 * qui se remplissent un à un, ballon qui rebondit de case en case). Décision
 * fondateur 2026-08-17 : la scène EST le contenu — AUCUN logo de club par-dessus.
 * Une seule scène pour les deux thèmes : les couleurs lisent les tokens de
 * `index.css` (var(--background|card|muted|border|muted-foreground)) et l'accent
 * du club via `var(--accent)` (jamais de littéral). Keyframes : `GenerationWaiting.css`.
 * `prefers-reduced-motion` géré dans ce CSS (animations coupées, ballon figé).
 */
export function GenerationWaiting() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setI((n) => (n + 1) % PHRASES.length), 3000);
    return () => clearInterval(t);
  }, []);
  return (
    <div className="flex flex-col items-center gap-6 py-8 text-center">
      {/* Cadre : surface distincte du fond de page (bg-card), bordure + arrondi, overflow
          masqué. `max-w-[560px]` borne la hauteur (~350 px, ratio 8:5) pour que scène + titre
          + phrase + note tiennent dans 720 px de haut sans scroller. */}
      <div className="w-full max-w-[560px] overflow-hidden rounded-[14px] border border-border bg-card">
        <svg
          viewBox="0 0 800 500"
          className="block h-auto w-full"
          role="img"
          aria-label="Des créneaux se placent un à un sur la grille du planning"
        >
        <defs>
          <clipPath id="gw-bands">
            <rect x="0" y="0" width="230" height="500" />
            <rect x="570" y="0" width="230" height="500" />
          </clipPath>
        </defs>
        <rect width="800" height="500" fill="var(--card)" />

        {/* Terrain en filigrane sur les deux bandes latérales. Tracé en `muted-foreground`
            (et non `border`, trop proche de la surface pour se lire) à faible opacité : un
            filigrane VISIBLE mais en retrait des créneaux qui se remplissent. */}
        <g clipPath="url(#gw-bands)" fill="none" stroke="var(--muted-foreground)" strokeWidth="2" opacity=".4">
          <rect x="16" y="16" width="768" height="468" rx="3" />
          <path d="M16 90h124a94 94 0 0 1 0 320H16" />
          <path d="M784 90H660a94 94 0 0 0 0 320h124" />
          <path d="M16 160h56v180H16z" />
          <path d="M784 160h-56v180h56z" />
          <circle cx="72" cy="250" r="34" />
          <circle cx="728" cy="250" r="34" />
        </g>

        {/* Indicateur circulaire (accent du club sur le balayage + l'aiguille) */}
        <g>
          <circle cx="44" cy="52" r="14" fill="none" stroke="var(--border)" strokeWidth="3" />
          <circle
            className="gw-anim"
            cx="44"
            cy="52"
            r="14"
            fill="none"
            stroke="var(--accent)"
            strokeWidth="3"
            strokeLinecap="round"
            strokeDasharray="88"
            strokeDashoffset="26"
            transform="rotate(-90 44 52)"
            style={{ animation: "gw-sweep 8s linear infinite" }}
          />
          <path
            className="gw-anim"
            d="M44 52v-8"
            fill="none"
            stroke="var(--accent)"
            strokeWidth="2.5"
            strokeLinecap="round"
            style={{ animation: "gw-spin 4s linear infinite", transformOrigin: "44px 52px" }}
          />
          <rect x="70" y="46" width="86" height="4" rx="2" fill="var(--muted-foreground)" opacity=".28" />
          <rect x="70" y="56" width="52" height="4" rx="2" fill="var(--muted-foreground)" opacity=".16" />
        </g>

        {/* Grille de créneaux vides */}
        <g fill="var(--card)" stroke="var(--border)" strokeWidth="1.5">
          <rect x="28" y="98" width="92" height="82" rx="8" />
          <rect x="134" y="98" width="92" height="82" rx="8" />
          <rect x="28" y="192" width="92" height="82" rx="8" />
          <rect x="134" y="192" width="92" height="82" rx="8" />
          <rect x="28" y="286" width="92" height="82" rx="8" />
          <rect x="134" y="286" width="92" height="82" rx="8" />
          <rect x="28" y="380" width="92" height="82" rx="8" />
          <rect x="134" y="380" width="92" height="82" rx="8" />
          <rect x="574" y="98" width="92" height="82" rx="8" />
          <rect x="680" y="98" width="92" height="82" rx="8" />
          <rect x="574" y="192" width="92" height="82" rx="8" />
          <rect x="680" y="192" width="92" height="82" rx="8" />
          <rect x="574" y="286" width="92" height="82" rx="8" />
          <rect x="680" y="286" width="92" height="82" rx="8" />
          <rect x="574" y="380" width="92" height="82" rx="8" />
          <rect x="680" y="380" width="92" height="82" rx="8" />
        </g>

        {/* Créneaux gauche qui se remplissent (coche = accent club) */}
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear .56s infinite" }}>
          <rect x="28" y="98" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="42" y="118" width="46" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="42" y="132" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M42 156l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 2s infinite" }}>
          <rect x="134" y="192" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="148" y="212" width="52" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="148" y="226" width="26" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M148 250l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 3.6s infinite" }}>
          <rect x="28" y="286" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="42" y="306" width="40" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="42" y="320" width="34" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M42 344l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 5.2s infinite" }}>
          <rect x="134" y="380" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="148" y="400" width="48" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="148" y="414" width="28" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M148 438l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>

        {/* Nuages à l'atterrissage du ballon (accent) */}
        <circle className="gw-anim gw-puffring" cx="74" cy="139" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear .56s infinite", transformBox: "fill-box", transformOrigin: "center" }} />
        <circle className="gw-anim gw-puffring" cx="180" cy="233" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear 2s infinite", transformBox: "fill-box", transformOrigin: "center" }} />
        <circle className="gw-anim gw-puffring" cx="74" cy="327" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear 3.6s infinite", transformBox: "fill-box", transformOrigin: "center" }} />
        <circle className="gw-anim gw-puffring" cx="180" cy="421" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear 5.2s infinite", transformBox: "fill-box", transformOrigin: "center" }} />

        {/* Ballon qui rebondit de case en case */}
        <g className="gw-anim gw-bx" style={{ animation: "gw-hopX 8s linear infinite" }}>
          <g className="gw-anim gw-by" style={{ animation: "gw-hopY 8s linear infinite" }}>
            <g className="gw-anim" style={{ animation: "gw-squash 8s linear infinite", transformBox: "fill-box", transformOrigin: "bottom center" }}>
              <g className="gw-anim" style={{ animation: "gw-ballFade 8s linear infinite" }}>
                <ellipse cx="0" cy="3" rx="15" ry="4" fill="#000" opacity=".35" />
                <circle cx="0" cy="-15" r="15" fill="var(--muted)" stroke="var(--muted-foreground)" strokeWidth="2" />
                <path d="M-15-15h30M0-30v30M-11-26A21 21 0 0 0-11-4M11-26A21 21 0 0 1 11-4" fill="none" stroke="var(--muted-foreground)" strokeWidth="1.5" />
              </g>
            </g>
          </g>
        </g>

        {/* Créneaux droite qui se remplissent (barre de contenu) */}
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear .3s infinite" }}>
          <rect x="574" y="98" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="118" width="50" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="132" width="28" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="152" width="64" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear .75s infinite" }}>
          <rect x="680" y="98" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="118" width="42" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="132" width="32" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="152" width="56" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 1.2s infinite" }}>
          <rect x="574" y="192" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="212" width="44" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="226" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="246" width="60" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 1.65s infinite" }}>
          <rect x="680" y="192" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="212" width="52" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="226" width="24" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="246" width="48" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 2.1s infinite" }}>
          <rect x="574" y="286" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="306" width="48" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="320" width="26" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="340" width="58" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 2.55s infinite" }}>
          <rect x="680" y="286" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="306" width="38" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="320" width="34" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="340" width="62" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 3s infinite" }}>
          <rect x="574" y="380" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="400" width="54" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="414" width="22" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="434" width="50" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 3.45s infinite" }}>
          <rect x="680" y="380" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="400" width="46" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="414" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="434" width="54" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        </svg>
      </div>

      <div className="space-y-1">
        <p className="text-lg font-medium">Génération du planning…</p>
        <p key={i} className="animate-in fade-in text-sm text-muted-foreground">
          {PHRASES[i]}
        </p>
      </div>
      <p className="max-w-sm text-xs text-muted-foreground">La génération peut prendre 1 à 3 min selon la taille du club. Vous pouvez laisser cet écran ouvert.</p>
    </div>
  );
}
