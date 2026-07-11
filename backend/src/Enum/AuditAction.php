<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * RGPD — actions tracées dans le journal d'audit append-only (accountability).
 * Liste FERMÉE et volontairement courte : événements d'auth, opérations
 * destructrices et exercices de droits — pas un log applicatif générique.
 */
enum AuditAction: string
{
    case AUTH_REGISTER = 'auth.register';
    case AUTH_LOGIN_FAILED = 'auth.login_failed';
    case ACCOUNT_ERASED = 'account.erased';
    case EXPORT_USER = 'export.user';
    case EXPORT_CLUB = 'export.club';
    case ENTITY_DELETED = 'entity.deleted';
    case SEASON_RESET = 'season.reset';
    case SEASON_PURGED = 'season.purged';
    case CLUB_PURGED = 'club.purged';
}
