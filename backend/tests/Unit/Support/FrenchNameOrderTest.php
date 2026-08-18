<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\FrenchNameOrder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * L'ordre des noms dans les documents SORTANTS (PDF, tableur).
 *
 * Une comparaison PHP brute range « École » et « Étoile » APRÈS « Zola » — un caractère
 * accentué pèse plus qu'un « z » en UTF-8. Ce test épingle la correction et, surtout, la
 * PARITÉ avec l'écran : le front trie ses gymnases avec `localeCompare("fr")`
 * (`shared/lib/venueOrder.ts`), un export qui trierait autrement contredirait la liste que
 * le gestionnaire avait sous les yeux.
 */
#[Group('phase1')]
final class FrenchNameOrderTest extends TestCase
{
    public function testAccentedNamesSortWhereAFrenchReaderExpectsThem(): void
    {
        $names = ['Zola', 'Étoile', 'Armand', 'École'];
        usort($names, FrenchNameOrder::compare(...));

        self::assertSame(['Armand', 'École', 'Étoile', 'Zola'], $names);
    }

    public function testCaseIsIgnoredSoALowercaseNameDoesNotLandLast(): void
    {
        $names = ['Zola', 'alpha', 'Béta'];
        usort($names, FrenchNameOrder::compare(...));

        self::assertSame(['alpha', 'Béta', 'Zola'], $names);
    }

    /** Falsification : avec `<=>`, cet ordre-là sortait « Armand, Zola, École » — le bug corrigé. */
    public function testRawComparisonIsNotWhatWeShip(): void
    {
        $raw = ['Zola', 'École', 'Armand'];
        usort($raw, static fn (string $a, string $b): int => $a <=> $b);

        self::assertSame(['Armand', 'Zola', 'École'], $raw, 'le comparateur brut relègue bien les accents : c’est ce qu’on ne veut plus');
    }
}
