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

// Mini-grille centrale : cases (1-indexées, grille 4×4) qui se remplissent à l'accent,
// avec les positions et délais échelonnés du design (drop 6 s en boucle).
const FILLED_CELL_DELAYS: Record<number, string> = { 1: ".2s", 3: "1.1s", 6: "1.9s", 8: "3.4s", 9: "2.6s", 15: "4.2s" };

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
 *
 * ⚠ Retour fondateur 2026-08-18 — le cadre était borné à `max-w-[640px]` : sur un shell
 * devenu pleine largeur, la génération laissait de l'espace mort à gauche ET à droite.
 * Le cap ne pouvait pas simplement sauter : le dessin était UN svg 800×500 en `h-auto`,
 * dont la hauteur suit la largeur (~1100 px de haut sur un écran large).
 *
 * D'où la composition actuelle : le décor ne vit QUE dans deux bandes latérales (le
 * centre, zone protégée du design, n'a jamais rien porté d'autre que les textes). Ces
 * bandes sont donc deux `<svg>` **ancrés aux bords**, cadrés par leur seule `viewBox`
 * (`0 0 230 500` et `570 0 230 500`) — les coordonnées du dessin d'origine sont
 * INCHANGÉES, comme les keyframes (exprimées en unités utilisateur du svg, donc mises
 * à l'échelle avec leur bande). La hauteur du cadre est fixée, la largeur s'étire : le
 * centre grandit avec l'écran, jamais la hauteur. `max-w-[26%]` par bande garantit au
 * centre 48 % du cadre quelle que soit la largeur ; sous `sm`, les bandes s'effacent et
 * le centre prend tout (deux bandes à leur taille minimale mangeraient le texte).
 */
export function GenerationWaiting() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setI((n) => (n + 1) % PHRASES.length), 3000);
    return () => clearInterval(t);
  }, []);
  return (
    <div className="flex justify-center py-8">
      {/* Cadre : surface distincte du fond de page (bg-card), bordure + arrondi, overflow
          masqué. Pleine largeur, hauteur BORNÉE (le décor s'ancre, il ne s'agrandit plus). */}
      <div
        data-testid="gen-scene-frame"
        className="relative h-[22rem] w-full overflow-hidden rounded-[14px] border border-border bg-card sm:h-[26rem]"
      >
        {/* Bande GAUCHE — purement décorative pour le lecteur d'écran : une seule image
            pour toute la scène, le sens vit dans les textes du centre. C'est elle qui
            porte le `role="img"` (la bande droite est muette : deux images feraient lire
            la scène deux fois). */}
        <svg
          data-testid="gen-band-left"
          viewBox="0 0 230 500"
          preserveAspectRatio="xMinYMid meet"
          className="absolute inset-y-0 left-0 hidden h-full w-auto max-w-[26%] sm:block"
          role="img"
          aria-label="Des créneaux se placent un à un sur la grille du planning"
        >
        {/* Terrain en filigrane. Tracé en `muted-foreground` (et non `border`, trop proche
            de la surface pour se lire) à faible opacité : un filigrane VISIBLE mais en
            retrait des créneaux qui se remplissent. Le tracé est celui du dessin ENTIER
            (il court d'un bord à l'autre) — la viewBox de la bande le cadre, plus besoin
            du clipPath qui découpait les deux fenêtres dans un svg unique. */}
        <g fill="none" stroke="var(--muted-foreground)" strokeWidth="2" opacity=".4">
          <rect x="16" y="16" width="768" height="468" rx="3" />
          <path d="M16 90h124a94 94 0 0 1 0 320H16" />
          <path d="M16 160h56v180H16z" />
          <circle cx="72" cy="250" r="34" />
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

        {/* Ballon qui rebondit de case en case (translations en unités utilisateur : elles
            suivent l'échelle de la bande, quelle que soit la largeur du cadre) */}
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
        </svg>

        {/* Bande DROITE — même dessin, cadré sur sa moitié par sa seule viewBox. Muette
            pour le lecteur d'écran (la bande gauche porte déjà l'image de la scène). */}
        <svg
          data-testid="gen-band-right"
          viewBox="570 0 230 500"
          preserveAspectRatio="xMaxYMid meet"
          className="absolute inset-y-0 right-0 hidden h-full w-auto max-w-[26%] sm:block"
          aria-hidden="true"
        >
        <g fill="none" stroke="var(--muted-foreground)" strokeWidth="2" opacity=".4">
          <rect x="16" y="16" width="768" height="468" rx="3" />
          <path d="M784 90H660a94 94 0 0 0 0 320h124" />
          <path d="M784 160h-56v180h56z" />
          <circle cx="728" cy="250" r="34" />
        </g>

        {/* Grille de créneaux vides */}
        <g fill="var(--card)" stroke="var(--border)" strokeWidth="1.5">
          <rect x="574" y="98" width="92" height="82" rx="8" />
          <rect x="680" y="98" width="92" height="82" rx="8" />
          <rect x="574" y="192" width="92" height="82" rx="8" />
          <rect x="680" y="192" width="92" height="82" rx="8" />
          <rect x="574" y="286" width="92" height="82" rx="8" />
          <rect x="680" y="286" width="92" height="82" rx="8" />
          <rect x="574" y="380" width="92" height="82" rx="8" />
          <rect x="680" y="380" width="92" height="82" rx="8" />
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

        {/* CENTRE de la scène, entre les deux bandes : mini-grille 4×4 qui se remplit +
            ligne de balayage + les trois niveaux de texte. Overlay HTML (pas dans le SVG) :
            lisible par lecteur d'écran, contrairement au décor `role="img"`. Ses insets
            réservent aux bandes leur 26 % — le texte ne peut donc jamais les chevaucher ;
            sous `sm` (bandes masquées) il prend toute la largeur. */}
        <div className="pointer-events-none absolute inset-y-0 inset-x-4 flex flex-col items-center justify-center gap-6 text-center sm:inset-x-[27%]">
          {/* Mini-grille animée — décorative (le sens est porté par les textes ci-dessous). */}
          <div aria-hidden="true" data-testid="gen-minigrid" className="relative" style={{ width: 200, height: 140 }}>
            <div className="absolute inset-0 grid grid-cols-4 grid-rows-4 gap-[6px]">
              {Array.from({ length: 16 }, (_, k) => (
                <span key={k} className="rounded-[5px] border border-border bg-muted" />
              ))}
            </div>
            <div className="absolute inset-0 grid grid-cols-4 grid-rows-4 gap-[6px]">
              {Array.from({ length: 16 }, (_, k) => {
                // Cases remplies à l'accent du club (positions et délais du design).
                const delay = FILLED_CELL_DELAYS[k + 1];
                return void 0 !== delay ? (
                  <span
                    key={k}
                    className="gw-anim rounded-[5px]"
                    style={{
                      background: "color-mix(in oklch, var(--accent) 22%, transparent)",
                      border: "1px solid color-mix(in oklch, var(--accent) 65%, transparent)",
                      opacity: 0,
                      animation: `gw-drop 6s ease-out ${delay} infinite`,
                    }}
                  />
                ) : (
                  <span key={k} />
                );
              })}
            </div>
            <div
              className="gw-anim absolute inset-x-0 top-0 h-[2px]"
              style={{
                background: "linear-gradient(90deg, transparent, var(--accent), transparent)",
                animation: "gw-scanline 6s linear infinite",
              }}
            />
          </div>

          {/* Titre + phrase tournante — région live (annoncée poliment au lecteur d'écran).
              Le texte habille sur 2-3 lignes dans le couloir laissé libre entre les bandes. */}
          <div role="status" aria-live="polite" className="flex w-full max-w-md flex-col gap-1">
            <p className="text-lg font-medium text-foreground">Génération du planning…</p>
            <p key={i} className="animate-in fade-in text-sm text-muted-foreground">
              {PHRASES[i]}
            </p>
          </div>
          <p className="w-full max-w-md text-xs leading-relaxed text-muted-foreground">La génération peut prendre 1 à 3 min selon la taille du club. Vous pouvez laisser cet écran ouvert.</p>
        </div>
      </div>
    </div>
  );
}
