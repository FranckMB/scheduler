<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * P1-1 (PR B) — les rôles ASSIGNABLES d'une adhésion club. Deux seulement :
 * Gestionnaire (écrit tout) et Membre (lit tout ; ses écritures sont déjà
 * refusées par l'enforcement management-par-défaut de PR A). Toute écriture de
 * rôle (approbation, changement de rôle) se valide contre cet enum — une valeur
 * hors liste est un 422, jamais persistée.
 *
 * ⚠ Ce n'est PAS la liste des rôles LISIBLES comme management : 'owner'
 * (historique, jamais réécrit) reste toléré en lecture par
 * ClubUserRepository::MANAGEMENT_ROLES, mais il n'est ni proposé ni assigné.
 * Aucune contrainte DB ne borne la colonne `role` : des valeurs héritées
 * ('owner', 'editor', 'viewer') doivent rester lisibles.
 */
enum ClubRole: string
{
    case MANAGER = 'admin';
    case MEMBER = 'member';
}
