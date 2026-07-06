# Vacances scolaires & jours fériés — référentiels calendaires

Last verified @ 2026-07-06 (graduation depuis `specs/evolution/roadmap.md` §2, PRs #53/#62/#63)

Feed d'affichage du cockpit (accueil temporel) : vacances scolaires de la zone du club + jours fériés applicables. **Display-only — jamais consommé par le solveur** : si un férié ou une vacance gêne un entraînement, le gestionnaire pose une période (`CalendarEntry` `closure`/`holiday`), il n'y a aucune règle implicite.

## Modèle

Deux tables **globales** (référentiel national partagé, pas de `club_id`, hors RLS) :

| Table | Entité | Clé naturelle (upsert) | Contenu |
|-------|--------|------------------------|---------|
| `school_holiday_period` | `SchoolHolidayPeriod` | `(zone, holiday_type, school_year)` | une période de vacances par zone et année scolaire |
| `public_holiday` | `PublicHoliday` | `(zone, holiday_date)` | un férié daté ; `zone='NATIONAL'` (métropole, tous clubs) ou code territoire pour les extras |

### Zones scolaires — 13 codes

`SchoolZoneResolver::ZONES` (source de vérité unique, réutilisée par `FrenchSchoolCalendarMapper` et gardée par un test de cohérence) : `A`, `B`, `C`, `CORSE` + 9 DOM/TOM (`GUADELOUPE`, `GUYANE`, `MARTINIQUE`, `MAYOTTE`, `NOUVELLE_CALEDONIE`, `POLYNESIE`, `REUNION`, `SAINT_PIERRE_MIQUELON`, `WALLIS_FUTUNA`).

La zone du club (`Club.schoolZone`) est **dérivée du code FFBB** au register + backfill : 3 lettres de ligue + 4 chiffres zéro-paddés = département (`GES0067060` → 67 → B ; `GUY0973021` → 973 → GUYANE ; Corse `2A`/`2B`/`20` → CORSE). Extraction **best-effort** (format FFBB non officiellement vérifié) : illisible → `null`, saisie manuelle (PATCH club), jamais écrasée si déjà renseignée.

## Alimentation (commandes console, idempotentes)

| Commande | Source | Fenêtre | Notes |
|----------|--------|---------|-------|
| `app:school-holidays:import` | API officielle Éducation nationale (ODS `data.education.gouv.fr/api/explore/v2.1`, dataset `fr-en-calendrier-scolaire`) | année scolaire courante + N+1 | pagination ODS ; filtre `population="-"` (vacances de trimestre) **or** `"Élèves"` (été côté élèves — l'été « Enseignants » est écarté) |
| `app:school-holidays:seed` | JSON versionné dans le repo | — | **fallback hors-ligne**, même upsert |
| `app:public-holidays:import` | API etalab `calendrier.api.gouv.fr/jours-feries/{zone}.json` (metropole + 9 territoires) | année civile courante + N+1 | métropole → `NATIONAL` ; extras territoriaux = **diff territoire − métropole** tagués du code territoire ; territoire non publié par etalab → warn + skip (saisie manuelle en attendant) |

Règles de mapping vacances (`FrenchSchoolCalendarMapper`) :
- une période est une **vacance** ssi elle couvre **strictement plus de 3 jours ouvrés** (lun–ven) **et** son libellé ne commence pas par `Pont` — écarte ponts (l'Ascension = 4-5 jours calendaires mais 3 ouvrés), semaines courtes, marqueurs 1 jour ;
- `holidayType` = **slug ouvert** dérivé du libellé (`toussaint`, `noel`… mais aussi `carnaval`, `ete_austral`, `apres_1ere_periode` pour les DOM/TOM) — pas d'enum fermé, les calendriers territoriaux ont leurs propres régimes.

Exécution **manuelle** aujourd'hui (cron annuel + bouton superadmin différés — rattachés à la future console superadmin, voir roadmap §2).

## Lecture (API)

Deux routes custom `GET`, documentées dans l'OpenAPI via `CustomRoutesOpenApiFactory` :

| Route | Comportement |
|-------|--------------|
| `GET /api/school-holidays` | vacances de la **zone du club** dans la fenêtre `from`/`to` (défaut : saison active). Zone `null` → `items: []` (le frontend affiche un CTA « renseigner la zone ») |
| `GET /api/public-holidays` | fériés `NATIONAL` **∪** extras du territoire du club, même fenêtre. Zone `null` → NATIONAL quand même (les fériés nationaux s'appliquent à tout club). Flag `national` par item |

`from`/`to` fournis mais invalides → 400 (pas de fallback silencieux sur la saison) ; pas de fenêtre du tout (ni params ni saison active) → 400.

## Hors périmètre assumé

- **Alsace-Moselle** (Vendredi Saint / 26 décembre) : départements 57/67/68 en zone B sans zone fériés dédiée.
- **Saint-Barthélemy / Saint-Martin** (977/978) : hors `SchoolZoneResolver::ZONES`.
- **Fallback JSON offline pour les fériés** : non construit (parité avec le seed vacances non faite).
- **Rendu cockpit des fériés** (pastille frontend) : à venir — voir roadmap §2.

## Gardes

`SchoolZoneResolverTest` · `FrenchSchoolCalendarMapperTest` · `PublicHolidayMapperTest` (unit) · `SchoolHolidaysApiTest` · `PublicHolidaysApiTest` · `ImportSchoolHolidaysCommandTest` · `ImportPublicHolidaysCommandTest` · `SeedSchoolHolidaysCommandTest` (integration).
