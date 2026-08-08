<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * D-06 — une liste de valeurs valides ne se recopie pas dans un DTO quand un enum la porte.
 *
 * Au 2026-08-08, **12 des 20 `Assert\Choice`** écrivaient à la main une liste strictement
 * identique à celle d'un enum du projet, pendant que **5 autres** utilisaient déjà la forme
 * `callback` — le patron existait donc, appliqué au quart des cas.
 *
 * Ce que la divergence coûte, et pourquoi elle est SILENCIEUSE dans le sens qui compte :
 * ajouter un cas à l'enum sans toucher au DTO fait **rejeter en 422** une valeur pourtant
 * légitime. La capacité existe, elle est inatteignable par l'API, et **aucun test ne la
 * couvre puisqu'elle vient d'être créée**. C'est exactement le motif SEC-13 (`FACILITY_CAPACITY`
 * honorée par le moteur, créable par personne), appliqué à un champ.
 *
 * ⚠ Ce test n'interdit pas les listes en dur : il interdit celles qui **doublent un enum**.
 * `ClubInput::$schoolZone` (constante partagée `SchoolZoneResolver::ZONES`) reste légitime,
 * comme toute liste qui n'a pas d'enum jumeau.
 */
#[Group('phase1')]
final class EnumChoicesAreDerivedTest extends TestCase
{
    private const string DTO_DIR = __DIR__ . '/../../src/Dto';

    private const string ENUM_DIR = __DIR__ . '/../../src/Enum';

    public function testNoChoiceRecopiesAnExistingEnum(): void
    {
        $enums = $this->enumsByValueSet();

        $offenders = [];
        foreach ($this->hardcodedChoices() as [$dto, $property, $values]) {
            sort($values);
            $key = implode('|', $values);
            if (isset($enums[$key])) {
                $offenders[] = \sprintf('%s::$%s recopie %s', $dto, $property, $enums[$key]);
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces contraintes recopient la liste d'un enum au lieu d'en dériver :\n  - %s\n\n"
            . "Remplacer par `#[Assert\\Choice(callback: [MonEnum::class, 'values'])]` (le trait\n"
            . "App\\Enum\\HasValues fournit `values()`). Une liste recopiée fait rejeter en 422 la\n"
            . 'valeur ajoutée à l\'enum : la capacité existe et devient inatteignable, sans un test rouge.',
            implode("\n  - ", $offenders),
        ));
    }

    /**
     * Les `Assert\Choice` qui portent une liste littérale, par DTO et propriété.
     *
     * @return list<array{string, string, list<string>}>
     */
    private function hardcodedChoices(): array
    {
        $found = [];
        foreach (glob(self::DTO_DIR . '/*.php') ?: [] as $file) {
            $class = 'App\\Dto\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            foreach (new ReflectionClass($class)->getProperties() as $property) {
                foreach ($property->getAttributes(Choice::class) as $attribute) {
                    /** @var array<string, mixed> $args */
                    $args = $attribute->getArguments();
                    $choices = $args['choices'] ?? ($args[0] ?? null);
                    if (!\is_array($choices) || [] === $choices) {
                        continue; // forme `callback` (ce qu'on veut) ou constante partagée
                    }

                    $values = array_values(array_filter($choices, is_string(...)));
                    if (\count($values) === \count($choices)) {
                        $found[] = [basename($file, '.php'), $property->getName(), $values];
                    }
                }
            }
        }

        self::assertNotEmpty(
            glob(self::DTO_DIR . '/*.php') ?: [],
            'Aucun DTO trouvé — le chemin a-t-il changé ? Ce test doit suivre, pas se taire.',
        );

        return $found;
    }

    /**
     * Jeu de valeurs (trié, joint) => nom de l'enum qui le porte.
     *
     * @return array<string, string>
     */
    private function enumsByValueSet(): array
    {
        $byValues = [];
        foreach (glob(self::ENUM_DIR . '/*.php') ?: [] as $file) {
            $class = 'App\\Enum\\' . basename($file, '.php');
            if (!enum_exists($class)) {
                continue;
            }

            $enum = new ReflectionEnum($class);
            if (!$enum->isBacked() || 'string' !== (string) $enum->getBackingType()) {
                continue;
            }

            $values = array_map(
                static fn (ReflectionEnumBackedCase $case): string => (string) $case->getBackingValue(),
                $enum->getCases(),
            );
            sort($values);
            $byValues[implode('|', $values)] = basename($file, '.php');
        }

        self::assertNotEmpty($byValues, 'Aucun enum adossé à une chaîne trouvé — le chemin a-t-il changé ?');

        return $byValues;
    }
}
