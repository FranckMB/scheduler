<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\Basketball\CategoryCatalog;
use App\Service\TeamTagService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-35 — un libellé de tranche d'âge ENCODE la règle serveur, et il a déjà menti deux fois.
 *
 * La portée d'un tag n'est pas décorative : `ScheduleConstraintBuilder::resolveTagToTeamIds`
 * l'éclate en N contraintes par équipe dans le payload. Un gestionnaire qui pose une règle
 * **HARD** « Jeune » sur la foi de son étiquette croit savoir qui elle atteint.
 *
 * Les deux mensonges, documentés dans `tagLabels.ts` :
 *  - « **Jeune (U13-U21)** » jusqu'à P4-63 — U21 a `ageMin` 19, donc SENIOR : la contrainte
 *    ne touchait PAS les U21 que le gestionnaire croyait couvrir ;
 *  - « **EMB (U9-U11)** » jusqu'à P4-42 — la règle taguait EMB tout `ageMax <= 12`, donc U5
 *    et U7 aussi : la contrainte touchait des équipes JAMAIS visées.
 *
 * `TeamTagScopeTest` (phase1) épingle la **règle**. Rien ne reliait la règle au **libellé** —
 * c'est-à-dire au seul élément que le gestionnaire lit avant de décider. Ce test ferme le
 * sens qui a mordu : le libellé annonce-t-il la portée que la règle applique ?
 *
 * ⚠ Il compare les CATÉGORIES en U, pas la prose : « Baby (U5-U7, Baby basket) » mentionne
 * légitimement une catégorie sans âge, et l'ordre des mots n'a pas à être figé par un test.
 */
#[Group('contract')]
final class TagLabelsMatchTheRuleTest extends TestCase
{
    private const string TAG_LABELS = __DIR__ . '/../../../frontend/src/features/wizard/lib/tagLabels.ts';

    private const string SEEDER = __DIR__ . '/../../src/Service/DefaultConstraintSeeder.php';

    /** Les tags dont le libellé annonce des bornes en U. */
    private const array AGE_TAGS = ['BABY', 'EMB', 'JEUNE'];

    public function testEveryLabelAnnouncesTheScopeTheRuleApplies(): void
    {
        $mismatches = [];

        foreach (self::AGE_TAGS as $tag) {
            $applied = $this->categoriesTaggedByTheRule($tag);
            $announced = $this->uCategoriesAnnouncedByLabel($tag);

            $missing = array_values(array_diff($applied, $announced));
            $overclaimed = array_values(array_diff($announced, $applied));

            foreach ($missing as $category) {
                $mismatches[] = \sprintf('%s : la règle tague %s, le libellé ne l\'annonce PAS', $tag, $category);
            }
            foreach ($overclaimed as $category) {
                $mismatches[] = \sprintf('%s : le libellé annonce %s, la règle ne le tague PAS', $tag, $category);
            }
        }

        self::assertSame([], $mismatches, \sprintf(
            "Le libellé d'une tranche d'âge ne dit plus ce que la règle applique :\n  - %s\n\n"
            . "Ce n'est pas cosmétique : la portée du tag EST ce que le solveur applique, et le\n"
            . "libellé est ce que le gestionnaire lit avant de poser une règle HARD.\n"
            . "« Jeune (U13-U21) » et « EMB (U9-U11) » ont menti ainsi (P4-63, P4-42).\n"
            . 'Corriger l\'étiquette OU la règle — mais décider laquelle dit le besoin, jamais aligner à l\'aveugle.',
            implode("\n  - ", $mismatches),
        ));
    }

    /**
     * Les noms de contraintes semées à la création d'un club REPRENNENT ces libellés
     * (« Jeune (U13-U18) · pas après 19:30 »). Un libellé corrigé d'un seul côté laisse un
     * club neuf avec une contrainte dont le nom ment sur sa propre portée.
     */
    public function testSeededConstraintNamesReuseTheSameLabels(): void
    {
        $seeder = file_get_contents(self::SEEDER);
        self::assertIsString($seeder);

        $drifted = [];
        foreach (self::AGE_TAGS as $tag) {
            $label = $this->labelOf($tag);
            // Le nom semé est « Groupe <libellé> · <prédicat> » (cible tag préfixée « Groupe »
            // par le wizard) : on cherche « <libellé> · » sans figer le préfixe.
            if (!str_contains($seeder, $label . ' ·')) {
                $drifted[] = \sprintf('%s : « %s » n\'apparaît plus dans les noms semés', $tag, $label);
            }
        }

        self::assertSame([], $drifted, \sprintf(
            "Les contraintes semées à la création d'un club n'utilisent plus le libellé de l'écran :\n  - %s\n"
            . 'Un club neuf naîtrait avec une contrainte dont le nom annonce une portée différente de celle de l\'écran.',
            implode("\n  - ", $drifted),
        ));
    }

    /**
     * Les catégories en U que la RÈGLE serveur tague réellement, en la faisant tourner sur
     * le catalogue officiel — jamais sur une liste recopiée.
     *
     * @return list<string>
     */
    private function categoriesTaggedByTheRule(string $tag): array
    {
        $service = $this->service();

        $tagged = [];
        foreach (CategoryCatalog::categories() as $category) {
            if (!str_starts_with($category['name'], 'U')) {
                continue; // « Baby basket », « Loisir »… : nommés à part dans les libellés
            }

            // `ageBracketsFor` rend un ENSEMBLE depuis le Volet A (ADULTE et SENIOR se
            // chevauchent) : on teste l'APPARTENANCE. Les AGE_TAGS gardés ici (BABY/EMB/JEUNE)
            // restent exclusifs, donc l'ensemble n'en contient qu'un pour ces catégories.
            if (\in_array($tag, $service->ageBracketsFor($category['name'], $category['ageMin'], $category['ageMax']), true)) {
                $tagged[] = $category['name'];
            }
        }

        sort($tagged);

        return $tagged;
    }

    /**
     * Les catégories en U que le LIBELLÉ annonce — « Jeune (U13-U18) » couvre U13, U15, U18.
     *
     * @return list<string>
     */
    private function uCategoriesAnnouncedByLabel(string $tag): array
    {
        $label = $this->labelOf($tag);

        preg_match_all('/U(\d+)/', $label, $bounds);
        self::assertNotEmpty($bounds[1], \sprintf('Le libellé de %s n\'annonce aucune borne en U : « %s ».', $tag, $label));

        $ages = array_map(intval(...), $bounds[1]);
        $low = min($ages);
        $high = max($ages);

        $covered = [];
        foreach (CategoryCatalog::categories() as $category) {
            if (1 !== preg_match('/^U(\d+)$/', $category['name'], $m)) {
                continue;
            }
            $age = (int) $m[1];
            if ($age >= $low && $age <= $high) {
                $covered[] = $category['name'];
            }
        }

        sort($covered);

        return $covered;
    }

    private function labelOf(string $tag): string
    {
        $source = file_get_contents(self::TAG_LABELS);
        self::assertIsString($source, \sprintf('Illisible : %s', self::TAG_LABELS));

        $found = preg_match(\sprintf('/^\s*%s: "([^"]+)"/m', preg_quote($tag, '/')), $source, $matches);
        self::assertSame(1, $found, \sprintf('Le libellé de %s a disparu de tagLabels.ts.', $tag));

        return $matches[1];
    }

    private function service(): TeamTagService
    {
        $reflection = new ReflectionClass(TeamTagService::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
