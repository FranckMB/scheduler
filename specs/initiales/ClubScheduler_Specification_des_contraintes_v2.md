ClubScheduler - Spécification des contraintes V2
================================================

Contexte
--------

Cette spécification remplace et complète le modèle actuel de contraintes du MVP.

L'objectif est de structurer les contraintes afin de :

-   conserver un moteur OR-Tools déterministe (`solver_seed=42`)
-   faciliter l'explication des conflits
-   permettre la capitalisation des contraintes d'une saison à l'autre
-   éviter la pollution du modèle par les modifications manuelles
-   conserver un système hybride où le gestionnaire reste décisionnaire

Principes fondamentaux
======================

1. Le produit n'est pas un générateur autonome
----------------------------------------------

ClubScheduler est un accélérateur d'itérations.

Le gestionnaire :

-   définit les contraintes
-   génère un planning
-   analyse les conflits éventuels
-   ajuste les contraintes
-   relance une génération

Le système doit expliquer les conflits détectés.

2. Génération déterministe
--------------------------

À contraintes identiques :

-   même entrée
-   même solver\_seed
-   même version du moteur

Le solver doit produire exactement le même planning.

Toute modification manuelle doit être explicitement transformée en contrainte ou verrouillage.

3. Contraintes saisonnières
---------------------------

Les contraintes appartiennent à une saison.

Au changement de saison :

-   les contraintes sont copiées
-   les copies restent modifiables
-   les contraintes historiques sont conservées

Scopes de contraintes
=====================

Une contrainte s'applique toujours à un scope précis.

Types de scope supportés
------------------------

    CLUB
    CATEGORY
    TEAM
    COACH
    FACILITY

Exemples
--------

### CLUB

    Tous les jeunes doivent commencer avant 19h30

### CATEGORY

    Toutes les équipes féminines interdites au gymnase B

### TEAM

    U15F préfère le mardi

### COACH

    Coach indisponible le jeudi

### FACILITY

    Gymnase A préfère accueillir les loisirs

Types de règles
===============

Le système ne doit plus utiliser uniquement des poids numériques.

Le gestionnaire exprime une intention métier.

HARD
----

Contrainte obligatoire.

Le solver ne peut pas la violer.

Exemples :

    Salle interdite à certaines équipes
    Coach indisponible
    Début avant 19h30 pour les jeunes

PREFERRED
---------

Préférence importante.

Le solver doit essayer de la satisfaire.

Peut être violée si nécessaire.

Exemples :

    Préférence de jour
    Préférence de salle
    Préférence d'horaire

BONUS
-----

Règle positive.

Le solver reçoit un bonus lorsqu'elle est satisfaite.

Exemples :

    Equipe fanion dans la meilleure salle
    Equipe région sur les meilleurs créneaux

LOCK
----

IMPORTANT :

LOCK n'est pas une contrainte métier.

LOCK représente une décision humaine sur le résultat.

Exemple :

    Le gestionnaire déplace manuellement une séance.

Cela ne crée pas automatiquement une nouvelle contrainte.

Le système doit proposer :

-   verrouillage uniquement
-   création d'une contrainte réutilisable

Familles de contraintes
=======================

Chaque contrainte doit appartenir à une famille métier.

    TIME
    DAY
    FACILITY
    COACH_AVAILABILITY
    FACILITY_CAPACITY
    ALLOCATION_PRIORITY
    DISTRIBUTION

Gestion des équipes
===================

Contraintes possibles
---------------------

### Horaire maximal

Exemple :

    Les U18 et moins ne peuvent pas débuter après 19h30

Support :

    HARD
    PREFERRED

### Jour interdit

Exemple :

    U15F interdit le vendredi

### Jour préféré

Exemple :

    U18M préfère le mercredi

### Salle interdite

Exemple :

    Equipe féminine interdite au gymnase B

### Salle préférée

Exemple :

    Loisirs préfèrent le gymnase A

Gestion des coachs
==================

Modèle
------

    TEAM_COACH

avec :

    MAIN
    ASSISTANT

Coach principal
---------------

Règle HARD implicite :

    Le coach principal doit être présent
    à tous les entraînements de l'équipe.

Contraintes coach
-----------------

### Jour interdit

    Repos du mardi

### Plage horaire interdite

    Indisponible avant 18h

### Nombre maximal de jours

    Maximum 4 jours de présence par semaine

### Non-chevauchement

Règle HARD implicite :

    Un coach ne peut pas être présent
    sur deux entraînements simultanés.

Gestion des salles
==================

Salle divisible
---------------

Une salle ne doit pas être représentée par un simple booléen.

Modèle attendu :

    divisible
    max_parallel_trainings

Exemples :

    Gymnase entier = 1
    Gymnase divisible = 2

Préférence de salle
-------------------

Exemple :

    Le gymnase A préfère recevoir les loisirs

Type :

    PREFERRED
    BONUS

Interdiction de salle
---------------------

Exemple :

    Le gymnase B interdit les équipes féminines

Type :

    HARD

Partage de salle
================

Les équipes peuvent autoriser ou refuser le partage.

Modèle :

    allow_shared_court

Exemples :

    Jeunes = généralement autorisé
    Seniors = généralement interdit

Disponibilités des installations
================================

La mairie fournit des plages de disponibilité.

Exemple :

    Lundi 18h00-22h00
    Mercredi 14h00-22h00

Le club découpe ensuite ces plages.

Granularité
-----------

Le MVP doit imposer une granularité fixe.

Exemple recommandé :

    30 minutes

Conflits
========

Le moteur doit produire des explications compréhensibles.

Exemple :

    U18F impossible à planifier.

    Raisons :

    - Coach principal disponible uniquement après 20h
    - Règle club : jeunes avant 19h30
    - Aucun créneau compatible

    Solutions possibles :

    1. Assouplir la règle horaire
    2. Modifier les disponibilités du coach
    3. Ajouter un créneau gymnase compatible

Le gestionnaire reste responsable de l'arbitrage.

Types de contraintes MVP
========================

Liste fermée pour le MVP.

Equipe
------

    TEAM_MAX_START_TIME
    TEAM_PREFERRED_DAY
    TEAM_FORBIDDEN_DAY
    TEAM_PREFERRED_FACILITY
    TEAM_FORBIDDEN_FACILITY

Coach
-----

    COACH_FORBIDDEN_DAY
    COACH_FORBIDDEN_TIME_RANGE
    COACH_MAX_PRESENCE_DAYS
    COACH_NO_OVERLAP

Salle
-----

    FACILITY_FORBIDDEN_TEAM_TAG
    FACILITY_PREFERRED_TEAM_TAG
    FACILITY_MAX_PARALLEL_TRAININGS

Club
----

    CLUB_YOUNG_MAX_START_TIME
    CLUB_SLOT_GRANULARITY
    CLUB_TRAINING_DURATION_BY_CATEGORY

Contraintes implicites HARD
===========================

Le moteur doit toujours appliquer :

    Un coach ne peut pas être à deux endroits en même temps.
    Une équipe ne peut pas avoir deux entraînements simultanés.
    Une salle ne peut pas dépasser sa capacité.
    Le coach principal doit être présent.
    Les séances doivent rester dans les plages municipales.
    Les verrouillages HARD doivent être respectés.

Objectif final
==============

Le système doit permettre :

1.  Définition des contraintes métier.
2.  Génération OR-Tools déterministe.
3.  Explication des conflits.
4.  Ajustement rapide des contraintes.
5.  Capitalisation des règles d'une saison à l'autre.
6.  Réduction maximale des itérations manuelles du gestionnaire.
