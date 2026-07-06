<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Derives a club's FFBB league (région) from its ffbbClubCode, mirroring
 * SchoolZoneResolver (dép. → zone scolaire). The FFBB code's 3-letter prefix
 * already encodes the league (ARA = Auvergne-Rhône-Alpes → AURA, GES = Grand
 * Est…), so we key on that prefix directly rather than re-deriving from the
 * department.
 *
 * Best-effort: an unreadable/unknown prefix returns null → the club falls back
 * to the federation-default catalog (AURA) in the envelope lookup
 * (LeagueMatchWindowRepository::findEnvelopeForLeague). The value is stored on
 * Club.league and never overwritten if already set.
 */
final class LeagueResolver
{
    /** FFBB 3-letter prefix → internal league key (extend as leagues are catalogued). */
    public const PREFIX_LEAGUE = [
        'ARA' => 'AURA',   // Auvergne-Rhône-Alpes
        'GES' => 'GEST',   // Grand Est
        'BFC' => 'BOFC',   // Bourgogne-Franche-Comté
        'NAQ' => 'NOAQ',   // Nouvelle-Aquitaine
        'OCC' => 'OCCI',   // Occitanie
        'IDF' => 'IDF',    // Île-de-France
        'HDF' => 'HDF',    // Hauts-de-France
        'NOR' => 'NORM',   // Normandie
        'PDL' => 'PDLL',   // Pays de la Loire
        'BRE' => 'BRET',   // Bretagne
        'CVL' => 'CVDL',   // Centre-Val de Loire
        'PCA' => 'PACA',   // Provence-Alpes-Côte d'Azur
        'COR' => 'CORS',   // Corse
    ];

    public function resolveFromFfbbCode(?string $ffbbCode): ?string
    {
        $prefix = $this->extractPrefix($ffbbCode);
        if (null === $prefix) {
            return null;
        }

        return self::PREFIX_LEAGUE[$prefix] ?? null;
    }

    /** The leading 3 letters of the FFBB code (the league prefix), or null. */
    private function extractPrefix(?string $ffbbCode): ?string
    {
        if (null === $ffbbCode || '' === $ffbbCode) {
            return null;
        }

        $code = strtoupper(trim($ffbbCode));
        if (1 === preg_match('/^([A-Z]{3})/', $code, $m)) {
            return $m[1];
        }

        return null;
    }
}
