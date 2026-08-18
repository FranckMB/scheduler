<?php

declare(strict_types=1);

namespace App\Support;

use Collator;

/**
 * L'ordre alphabétique FRANÇAIS des noms affichés (gymnases, équipes, catégories).
 *
 * Une comparaison PHP brute (`<=>`) compare des OCTETS : « École » et « Étoile » passent
 * APRÈS « Zola », parce qu'un caractère accentué pèse plus qu'un « z » en UTF-8. Un club
 * français a des gymnases et des équipes accentués — le document sortait donc avec une fin
 * de liste incompréhensible, et le tri avait l'air fait.
 *
 * `Collator` (ext-intl, présente dans l'image) range les accents à leur place et ignore la
 * casse au premier niveau, comme le fait `localeCompare("fr")` côté écran : le MÊME ordre
 * des deux côtés, pour qu'un export ne contredise jamais la liste qu'on avait sous les yeux.
 *
 * C'est de la PRÉSENTATION : l'ordre n'autorise ni n'interdit rien.
 */
final class FrenchNameOrder
{
    private static ?Collator $collator = null;

    /** Comparateur prêt pour `usort`/`uasort` : négatif, zéro, positif. */
    public static function compare(string $a, string $b): int
    {
        $result = self::collator()->compare($a, $b);

        // `Collator::compare` rend `false` s'il échoue (jamais vu ici, mais la signature
        // l'autorise) : on retombe alors sur la comparaison brute plutôt que sur un tri
        // arbitraire — un ordre imparfait vaut mieux qu'un ordre instable.
        return false === $result ? $a <=> $b : $result;
    }

    private static function collator(): Collator
    {
        // Le collator est coûteux à construire et sans état entre deux comparaisons : on le
        // garde. `PRIMARY` = casse et variantes ignorées (« alpha » ne tombe pas après « Zola »).
        if (!self::$collator instanceof Collator) {
            $collator = new Collator('fr_FR');
            $collator->setStrength(Collator::PRIMARY);
            self::$collator = $collator;
        }

        return self::$collator;
    }
}
