# Mettre la landing en ligne — ce qui manque, et pourquoi (P5-5)

> Document de cadrage écrit pour un fondateur **novice en infra** (demande du 2026-08-18). Il
> explique le vocabulaire, dessine la chaîne complète, et sépare ce qui se fait dans le dépôt de
> ce qui se fait sur la machine. Il vit dans `evolution/` tant que P5-5 est ouvert ; le jour de
> la publication, le comportement livré rejoint `courantes/` et ce fichier disparaît.

## 1. Où on en est

La page **existe et est finie** (`landing/`, construite le 2026-08-10, design repassé le
2026-08-11 : contrastes WCAG, fonte auto-hébergée OFL, rendu vérifié en 1440 et 390 px). Sept
questions de FAQ, une capture réelle du club démo, des offres sans montants.

Elle est **statique pure, zéro build** : du HTML, du CSS, une image, une fonte. On l'ouvre dans
un navigateur, elle marche. Rien à compiler, rien à installer.

`landing/config.js` porte déjà le nom tranché (`brand: "Amateo"`,
`contactEmail: "contact@amateo.app"`). **Il ne reste qu'une ligne à pointer** : `appUrl`, encore
sur le placeholder `https://app.example.fr`.

⚠ **Ce qui manque n'est donc pas la page — c'est le chemin qui l'amène jusqu'à un visiteur.**
Aujourd'hui `landing/` n'est référencé **nulle part** : ni dans `docker-compose.prod.yml`, ni
dans `docker/frontend/Dockerfile`, ni dans les deux configurations nginx. La page vit dans le
dépôt et n'en sort jamais.

## 2. Le vocabulaire, en une page

**DNS** — l'annuaire d'Internet. Il traduit `amateo.app` en l'adresse IP d'une machine. Vous avez
acheté le nom ; l'enregistrement qui le fait pointer vers la VM reste à poser.

**Caddy** — un serveur web installé **sur la VM, hors de Docker**. Son intérêt principal ici : il
obtient et renouvelle **tout seul** le certificat HTTPS (Let's Encrypt, gratuit). Vous n'avez
rien à faire pour le cadenas de la barre d'adresse.

**Le `Caddyfile`** — son fichier de configuration, sur la VM (`/etc/caddy/Caddyfile`). On y écrit
**un bloc par site** : un nom de domaine, puis ce qu'on fait des visiteurs qui arrivent dessus.

**`reverse_proxy`** — une façon de répondre : « je ne réponds pas moi-même, je transmets à
quelqu'un d'autre ». C'est ce que fait le bloc actuel : il transmet au nginx de l'app, dans
Docker, sur le port 8081.

**`file_server`** — l'autre façon : « je sers directement les fichiers d'un dossier ». C'est
exactement ce dont la landing a besoin — il n'y a aucune application derrière, juste des fichiers.

**`appUrl`** — rien à voir avec l'infra. Une ligne de `landing/config.js`, lue par le navigateur
au chargement. La page a des boutons « Essai gratuit » et « Se connecter » : il faut bien qu'ils
pointent vers l'app. C'est **la seule variable** de la page — la changer, c'est éditer une ligne
et recharger, sans rien recompiler.

## 3. La chaîne complète

```
   navigateur
       │  « amateo.app »
       ▼
     DNS  ──────────────►  l'IP de la VM
       │
       ▼
   ┌──────────────────────────────────────────────────────┐
   │  VM (Scaleway) — à créer                             │
   │                                                      │
   │   Caddy  (écoute 443, gère le certificat HTTPS)      │
   │     ├─ amateo.app       → file_server   → les FICHIERS de landing/
   │     ├─ www.amateo.app   → redirection permanente → amateo.app
   │     └─ app.amateo.app   → reverse_proxy → 127.0.0.1:8081
   │                                                │     │
   │   Docker (la stack)                            ▼     │
   │     nginx front (8081) ─► l'app React ─► API ─► base │
   └──────────────────────────────────────────────────────┘
```

**Le domaine nu est la vitrine, le sous-domaine `app.` est l'application.** C'est la convention
courante, et elle sert P5-5 : ce qu'on vend mérite l'adresse qu'on écrit sur une plaquette.

## 4. Ce qui manque, en trois tas

**En local : rien.** La page tourne déjà (`python3 -m http.server` dans `landing/`, ou un simple
double-clic sur le fichier). La landing et l'app **ne se parlent jamais** — le seul lien entre
elles est un lien hypertexte. Il n'y a rien à relier à un nginx local, et il n'y en aura jamais.

**Dans le dépôt : FAIT le 2026-08-18.**
- `appUrl` pointe `https://app.amateo.app` — sans slash final, les CTA concatènent ;
- le **workflow de déploiement dépose `landing/`** sous `$DEPLOY_PATH/landing` (§5) ;
- le `Caddyfile` est versionné en modèle ([`../../docs/ops/Caddyfile.example`](../../docs/ops/Caddyfile.example))
  et la procédure réécrite dans [`../../docs/ops/deploy.md`](../../docs/ops/deploy.md) §1.5 ;
- texte relu : aucune trace de l'ancien nom, la FAQ « où sont hébergées nos données » reste
  vraie pour une VM européenne.

**Sur la VM** (elle n'existe pas encore) : tout le §1 de `deploy.md` — créer la machine, Docker,
lancer la stack — puis les enregistrements DNS (nu, `www`, `app.`), puis Caddy avec ses trois
blocs (`docs/ops/Caddyfile.example`).

⚑ **Personne ne peut cocher P5-5 tant que la machine n'existe pas.** La partie dépôt est faite ;
la mise en ligne reste un geste du fondateur sur sa VM. Le dire évite une PR qui se donnerait pour
« publié ».

**Ce qu'il reste à faire, le jour J**, dans cet ordre : créer la VM (§1 de `deploy.md`) → poser les
enregistrements DNS → copier `Caddyfile.example` dans `/etc/caddy/Caddyfile` → armer
`DEPLOY_ENABLED` + les secrets → **déployer une première fois** (c'est ce déploiement qui crée
`$DEPLOY_PATH/landing`) → ouvrir les droits de lecture pour `caddy` (`deploy.md` §1.5) →
`systemctl reload caddy`. ⚠ Avant le premier déploiement, le domaine nu répond **404** : c'est
attendu, le dossier n'existe pas encore.

## 5. Comment la landing arrive sur la VM — tranché

**Par le workflow de déploiement** (décision fondateur, 2026-08-18), et non par une copie
manuelle. C'est cohérent avec ce qui existe : le job `deploy` de
[`.github/workflows/deploy.yml`](../../.github/workflows/deploy.yml) joint déjà la VM en SSH et y
dépose des fichiers par `scp` sous `DEPLOY_PATH` (défaut `/srv/clubscheduler`), derrière le
garde-fou `DEPLOY_ENABLED` — armé le jour où la VM existe.

Conséquence : la landing suit **la même version que l'app**, elle se redéploie et se **rollback**
avec elle. Une copie manuelle aurait créé une seconde vérité — la page en ligne aurait pu dater
d'une version que plus personne ne connaît.

⚠ Point à ne pas manquer à l'implémentation : la landing est du **statique servi par Caddy**, pas
par la stack Docker. Le dossier déposé doit donc être lisible par Caddy (chemin et droits), et le
bloc `file_server` doit pointer exactement dessus. C'est le seul endroit où les deux mondes se
touchent.

## 6. Ce que ce document ne tranche pas

- **Le montant des offres** — la page les présente sans chiffres, c'est délibéré.
- **Le contenu et le design** — validés le 2026-08-11, hors périmètre. Seule la relecture à voix
  haute reste, parce que le nom a changé depuis.
- **La configuration du registrar** — achat et zone DNS sont des gestes du fondateur.
