<?php

declare(strict_types=1);
use App\Message\Basketball\PopulateClubFromFfbbMessage;

/*
 * Alias de compatibilité pour les messages DÉJÀ EN FILE au moment d'un déploiement.
 *
 * Le transport `async` utilise le `PhpSerializer` par défaut : une enveloppe
 * poussée dans Redis porte le FQCN de la classe au moment du dispatch. Renommer
 * la classe (P4-17, `App\Message\…` → `App\Message\Basketball\…`) rend donc
 * INDÉCODABLES toutes les enveloppes en attente au moment où la nouvelle image
 * démarre : `MessageDecodingFailedException`, l'enveloppe est acquittée avant
 * d'atteindre le transport `failed`, et le worker sort.
 *
 * Concrètement, sans cet alias : un club qui vérifie son email quelques secondes
 * avant le déploiement est créé SANS aucune donnée FFBB — pas d'adresse, pas de
 * logo, pas de rattachement ligue/comité — et rien ne réessaie. L'échec est
 * silencieux (le handler est best-effort), et la seule reprise est un
 * `POST /api/club/ffbb-import` manuel que personne ne sait devoir déclencher.
 *
 * Chargé via `autoload.files` (composer.json) : l'alias existe donc AVANT tout
 * `unserialize()`, ce qui est la condition pour que PHP résolve l'ancien nom.
 *
 * Il vit dans `config/` et NON dans `src/` : `services.yaml` autowire `../src/`
 * en attendant une classe par fichier, et un fichier de script y casse le
 * chargement du conteneur (« Expected to find class App\compat_class_aliases »).
 *
 * ⚠ À SUPPRIMER une fois que plus aucune enveloppe de l'ancienne génération ne
 * peut traîner — en pratique après un déploiement dont on a vérifié que la file
 * `async` est vide (`php bin/console messenger:stats`).
 */
class_alias(
    PopulateClubFromFfbbMessage::class,
    'App\Message\PopulateClubFromFfbbMessage',
);
