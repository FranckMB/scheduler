# Identité visuelle par club (logo + couleur d'accent)

> **Base de réflexion** — feature discutée pendant la refonte frontend, **scaffoldée mais jamais branchée**. But : chaque club a sa **couleur d'accent** (et son **logo**), donnant une identité visuelle propre. Statut : ✅ livré · 🟡 partiel/scaffoldé · ⬜ à faire.

## Pourquoi

Le design system est construit autour d'**une seule couleur d'accent** (`--accent`) qui pilote primary / actif / highlight ; les surfaces et le texte restent neutres et AA-contrastés. L'intention explicite (commentaire `frontend/src/index.css`) : *« `--accent` is overridden per club later (logo…) »*. Aujourd'hui l'accent est un **bleu neutre par défaut**, identique pour tous les clubs — pas d'identité.

## État du scaffolding (ce qui existe déjà)

- 🟡 **`themeStore` a un slot `accent`** (`frontend/src/shared/stores/themeStore.ts`) — actuellement `null`, **jamais lu ni écrit**. C'est le point d'injection prévu.
- 🟡 **`--accent` en variable CSS** avec variantes **dark/light** (`--accent` + `--accent-foreground`), déjà consommée partout via les tokens Tailwind.
- ⬜ **Aucun champ `logo` / `color` / `accent` sur l'entité `Club`** (backend) — rien à persister.
- 🟡 **Surfaces où la marque apparaîtrait** : header (`AppLayout` → icône générique `CalendarCheck2`), écran d'attente de génération (`GenerateStep` → **initiale** du club dans un cercle qui pulse), page login, export PDF.
- ✅ Thème dark/light complet (`themeStore`, toggle) — l'accent devra fonctionner dans les deux.

## Périmètre de la feature

### 1. Couleur d'accent par club (le cœur)
- ⬜ **Backend** : champ `Club.accentColor` (hex/oklch) exposé dans `/api/me`.
- ⬜ **Frontend** : au login / `me`, écrire l'accent dans `themeStore.accent` et l'appliquer en surchargeant la variable CSS `--accent` (au niveau `:root` ou du layout).
- ⬜ **Contraste** : dériver `--accent-foreground` (et les variantes dark/light) pour rester **AA** quelle que soit la couleur choisie (garde-fou de lisibilité).
- ⬜ **Fallback** : couleur choisie absente → bleu neutre actuel (comportement d'aujourd'hui).

### 2. Logo par club
- ⬜ **Backend** : champ `Club.logoUrl` (ou upload + stockage) exposé dans `/api/me`.
- ⬜ **Upload** : écran de réglages club (image → validation format/taille → stockage).
- ⬜ **Affichage** : remplacer l'icône générique du header + l'**initiale** de l'écran d'attente par le logo ; option login + PDF.

### 3. Dérivation couleur ← logo (optionnel / plus tard)
- ⬜ Extraire la couleur dominante du logo uploadé pour **pré-remplir** `accentColor` (l'utilisateur peut ensuite ajuster). Alternative simple : choix manuel d'une couleur, sans extraction.

## Découpage possible (du plus petit au plus gros)

1. **MVP accent** : `Club.accentColor` + application front (slot `accent` déjà prêt) + garde-fou contraste. Petit, gros impact visuel.
2. **Logo** : champ + upload + affichage (header, écran d'attente).
3. **Extraction couleur depuis le logo** : confort, non bloquant.

## Questions ouvertes

1. **Accent = choix manuel** (color picker dans les réglages club) **ou dérivé du logo** (extraction) — ou les deux (dérivé pré-rempli, éditable) ?
2. **Stockage du logo** : URL externe, ou upload + stockage (où ? quota par plan ?).
3. **Garde-fou contraste** : imposer un accent AA (clamp automatique de la luminance) ou laisser libre au risque d'illisibilité ?
4. Le logo remplace-t-il l'**initiale** de l'écran d'attente, ou on garde l'initiale en fallback quand pas de logo ?

## Réfs

- Design tokens & intention : `frontend/src/index.css` (slot `--accent`, commentaire « per club »).
- Point d'injection : `frontend/src/shared/stores/themeStore.ts` (slot `accent`).
- Surfaces : `frontend/src/app/AppLayout.tsx`, `frontend/src/features/wizard/steps/GenerateStep.tsx` (mark d'attente).
