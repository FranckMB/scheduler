<?php

declare(strict_types=1);

namespace App\Seed;

/**
 * P2-4 PR 2bis — l'IDENTITÉ d'un seed BCCL : le même club réaliste (équipes,
 * gymnases, créneaux, contraintes, réservations — l'état terrain), sous deux
 * visages.
 *
 * - `dev()` : le BCCL réel (logo compris) — le club dev de `make fixtures`.
 * - `demo(password)` : le club de DÉMONSTRATION permanent — noms de club et de
 *   coachs FICTIFS (RGPD : l'écran part en rendez-vous), pas de logo BCCL, flag
 *   `is_demo` posé.
 *
 * Les gymnases gardent leurs noms/ancrages réels : ce sont des bâtiments
 * publics, et l'ancre fédérale fait marcher les écrans (stats, autocomplétion).
 */
final readonly class BcclSeedProfile
{
    /**
     * 26 identités fictives, une par coach du seed, DANS L'ORDRE de sa liste —
     * le remplacement est positionnel, donc déterministe d'un reset à l'autre.
     * Les libellés qui citent un coach (« %s - Indisponible mercredi ») suivent
     * automatiquement : l'entité est renommée AVANT que les contraintes ne
     * lisent son prénom.
     */
    private const array FICTIONAL_COACHES = [
        ['firstName' => 'Mathéo', 'lastName' => 'Verne'],
        ['firstName' => 'Salomé', 'lastName' => ''],
        ['firstName' => 'Edgar', 'lastName' => 'Rollin'],
        ['firstName' => 'Timo', 'lastName' => 'Grange'],
        ['firstName' => 'Ilyes', 'lastName' => ''],
        ['firstName' => 'Bastien', 'lastName' => ''],
        ['firstName' => 'Maud', 'lastName' => 'Ferrand'],
        ['firstName' => 'Côme', 'lastName' => ''],
        ['firstName' => 'Rayan', 'lastName' => ''],
        ['firstName' => 'Gaspard', 'lastName' => 'Loyer'],
        ['firstName' => 'Damien', 'lastName' => 'Vasseur'],
        ['firstName' => 'Lina', 'lastName' => ''],
        ['firstName' => 'Corentin', 'lastName' => ''],
        ['firstName' => 'Marco', 'lastName' => 'Bellini'],
        ['firstName' => 'Éva', 'lastName' => ''],
        ['firstName' => 'Fabrice', 'lastName' => ''],
        ['firstName' => 'Noah', 'lastName' => 'Carlier'],
        ['firstName' => 'Louna', 'lastName' => ''],
        ['firstName' => 'Rémi', 'lastName' => 'Deschamps'],
        ['firstName' => 'Maïwenn', 'lastName' => ''],
        ['firstName' => 'Jules', 'lastName' => ''],
        ['firstName' => 'Evan', 'lastName' => ''],
        ['firstName' => 'Assia', 'lastName' => ''],
        ['firstName' => 'Azou', 'lastName' => ''],
        ['firstName' => 'Charlie', 'lastName' => ''],
        ['firstName' => 'Jade', 'lastName' => ''],
    ];

    /** @param list<array{firstName: string, lastName: string}>|null $coachNames remplacement 1-à-1, null = noms du seed */
    private function __construct(
        public string $clubName,
        public string $clubSlug,
        public string $ffbbCode,
        public string $managerEmail,
        public string $managerFirstName,
        public string $managerLastName,
        public string $managerPassword,
        public bool $seedLogo,
        public bool $isDemo,
        public ?array $coachNames,
    ) {}

    public static function dev(): self
    {
        return new self(
            clubName: 'B CHARPENNES CROIX LUIZET',
            clubSlug: 'b-charpennes-croix-luizet',
            ffbbCode: 'ARA0069036',
            managerEmail: 'mara.mb@bccl.fr',
            managerFirstName: 'Mara',
            managerLastName: 'Mb',
            managerPassword: 'maraboubccl',
            seedLogo: true,
            isDemo: false,
            coachNames: null,
        );
    }

    public static function demo(string $managerPassword, string $managerEmail = 'demo-bccl@clubscheduler.fr'): self
    {
        return new self(
            clubName: 'Démo Basket Club',
            clubSlug: 'demo-basket-club',
            // Préfixe ARA conservé : la ligue (AURA) et la zone scolaire se résolvent
            // depuis le préfixe — un code fantaisiste casserait les deux écrans.
            // Le numéro est hors plage réelle : jamais un vrai club.
            ffbbCode: 'ARA9999999',
            managerEmail: $managerEmail,
            managerFirstName: 'Démo',
            managerLastName: 'ClubScheduler',
            managerPassword: $managerPassword,
            seedLogo: false,
            isDemo: true,
            coachNames: self::FICTIONAL_COACHES,
        );
    }
}
