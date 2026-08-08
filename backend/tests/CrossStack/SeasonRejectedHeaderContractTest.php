<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\EventListener\TenantFilterListener;
use App\Service\SeasonResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D-39 — `X-Season-Rejected` : un producteur, un consommateur, zéro test (jusqu'ici).
 *
 * Ce n'est pas un en-tête de confort. Quand la saison sélectionnée meurt (purgée, saison
 * d'un autre club, identifiant malformé), le backend **403 TOUTE requête qui la porte —
 * `/api/me` comprise**. Le frontend n'a donc aucun moyen de « demander ce qui se passe » :
 * son seul indice est ce marqueur, qui lui dit de lâcher la saison et de recharger. Sans
 * lui, l'application reste en **boucle 403 définitive**, sans chemin de retour.
 *
 * Le renommer côté backend, ou retirer sa lecture côté frontend, ne casse **aucun** test
 * fonctionnel : les deux moitiés continuent de « marcher », et c'est l'utilisateur qui
 * découvre l'impasse. D'où ce test de contrat, qui vérifie que les deux extrémités parlent
 * encore du même nom.
 *
 * `SeasonIsolationTest` (phase1) garde l'autre moitié : que le marqueur soit réellement
 * POSÉ sur les trois formes de rejet.
 */
#[Group('contract')]
final class SeasonRejectedHeaderContractTest extends TestCase
{
    private const string CLIENT = __DIR__ . '/../../../frontend/src/shared/api/client.ts';

    private const string SEASON_TRANSITION = __DIR__ . '/../../../frontend/src/app/seasonTransition.ts';

    public function testTheFrontendReadsTheHeaderTheBackendSends(): void
    {
        $client = file_get_contents(self::CLIENT);
        self::assertIsString($client, \sprintf('Illisible : %s', self::CLIENT));

        self::assertStringContainsString(
            \sprintf('headers.has("%s")', TenantFilterListener::STALE_SEASON_HEADER),
            $client,
            \sprintf(
                "Le frontend ne lit plus l'en-tête « %s » que le backend pose sur un rejet de saison.\n"
                . "Conséquence : sur une saison morte, l'app garde sa sélection et boucle en 403 —\n"
                . "y compris sur /api/me, donc SANS chemin de retour. Aucun test fonctionnel ne le voit :\n"
                . 'les deux moitiés « marchent », c\'est la jonction qui est rompue.',
                TenantFilterListener::STALE_SEASON_HEADER,
            ),
        );
    }

    /**
     * Le marqueur ne doit PAS servir de déclencheur à tout 403 : un refus d'autorisation
     * légitime doit continuer d'afficher son erreur au lieu d'effacer la saison et de
     * recharger la page. Le commentaire du client le dit ; ce test épingle le mécanisme.
     */
    public function testTheFrontendKeysOnTheMarkerAndNotOnAnyForbidden(): void
    {
        $client = file_get_contents(self::CLIENT);
        self::assertIsString($client);

        self::assertMatchesRegularExpression(
            '/status === 403 && [^\n]*headers\.has\("' . preg_quote(TenantFilterListener::STALE_SEASON_HEADER, '/') . '"\)/',
            $client,
            'L\'auto-réparation doit être conditionnée au marqueur ET au 403 — se déclencher sur n\'importe quel 403 effacerait la saison sur un simple refus de rôle.',
        );
    }

    /**
     * D-40 — le pivot de saison (15 juillet) est écrit des deux côtés.
     *
     * Le backend le porte en constante (`SeasonResolver::TRANSITION_MONTH_DAY`), le frontend
     * en littéral (`seasonYearOf`). Ils décident de la MÊME chose : dans quelle saison on se
     * trouve. S'ils divergent, le bandeau de bascule et le sélecteur pointent une saison
     * pendant que l'API en sert une autre — sans erreur, chacun étant cohérent avec lui-même.
     */
    public function testTheSeasonPivotIsTheSameOnBothSides(): void
    {
        $front = file_get_contents(self::SEASON_TRANSITION);
        self::assertIsString($front, \sprintf('Illisible : %s', self::SEASON_TRANSITION));

        self::assertStringContainsString(
            \sprintf('>= "%s"', SeasonResolver::TRANSITION_MONTH_DAY),
            $front,
            \sprintf(
                "Le frontend ne pivote plus la saison au %s comme le backend.\n"
                . 'Les deux resteraient cohérents avec eux-mêmes : l\'écran annoncerait une saison, l\'API en servirait une autre.',
                SeasonResolver::TRANSITION_MONTH_DAY,
            ),
        );
    }
}
