<?php

declare(strict_types=1);

namespace App\Export;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * P3-20 — quelle VUE l'image d'un export photographie.
 *
 * `GRID` = la grille du gestionnaire (jours × gymnases), le comportement historique ;
 * `CLUB` = la matrice équipes × jours, jusque-là visible seulement en feuilletant le PDF
 * ou le classeur Excel. Le PDF et le XLSX portent les DEUX vues quoi qu'il arrive : ce
 * choix ne concerne que l'image, qui ne se feuillette pas.
 *
 * ⚑ Cette valeur vient du corps de la requête et atteint un NOM DE FICHIER (jeton de vue
 * du fichier rendu) : elle ne peut donc pas être libre. La liste blanche vit ici, hors du
 * contrôleur, précisément pour être vérifiable sans base ni requête HTTP.
 */
final class ExportView
{
    public const GRID = 'grid';
    public const CLUB = 'club';

    /**
     * Lit la vue demandée dans un corps de requête déjà décodé.
     *
     * Absente, nulle ou vide → `GRID` (le défaut historique : un appelant qui ne connaît pas
     * ce réglage doit recevoir exactement ce qu'il recevait avant). Toute autre valeur est un
     * **400 explicite** — jamais un repli silencieux, qui rendrait une image « par club » en
     * grille sans que personne ne le sache.
     *
     * @return self::GRID|self::CLUB
     */
    public static function fromRequestBody(mixed $body): string
    {
        $view = \is_array($body) ? ($body['view'] ?? null) : null;
        if (null === $view || '' === $view) {
            return self::GRID;
        }
        if (self::GRID !== $view && self::CLUB !== $view) {
            throw new BadRequestHttpException('Unknown export view.');
        }

        return $view;
    }
}
