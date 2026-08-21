<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor;

use App\Dto\CalendarEntryInput;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\State\Processor\CalendarEntryStateProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * AUD-BCK-14 (P4-114) — **une valeur d'enum inconnue échoue BRUYAMMENT, elle ne se replie pas.**.
 *
 * Les trois parsers du processeur repliaient en silence : `?? EVENT` pour le genre d'entrée,
 * `?? ACTIVE` pour le statut, et `tryFrom()` nu pour le type de période (dont le `null` de
 * l'inconnu se confond avec le `null` de l'absent). C'est mot pour mot le motif qu'AUD-BCK-12 a
 * remplacé par un `throw` sur `Constraint` — resté en place ici.
 *
 * ⚠ **Ce que ce filet protège, et pourquoi il vaut plus qu'un détail de style.** Ces replis sont
 * inatteignables aujourd'hui : `CalendarEntryInput` porte un `Assert\Choice` sur les trois
 * champs. Mais le jour où l'un saute — un DTO réécrit, un type ajouté sans son entrée — une
 * **PÉRIODE deviendrait un ÉVÉNEMENT** enregistré comme tel, ou un type de période partirait à
 * NULL : les conséquences sont celles de l'ADR-0002 (grille possédée par la période, plans
 * ancrés), et rien ne le dirait. Un filet qui ne peut pas se déclencher est exactement ce qu'on
 * veut d'un filet.
 *
 * ⚑ **La différence assumée avec le patron de `Constraint`** : là-bas, les champs portent
 * `NotBlank` — l'absence est déjà invalide, le parser peut donc lever sur `null`. Ici les trois
 * champs sont **facultatifs et ont un défaut documenté** (genre absent = ÉVÉNEMENT, statut
 * absent = ACTIF, type de période absent = pas de période). On distingue donc les deux cas :
 * **absent → le défaut ; PRÉSENT mais inconnu → 422**. Confondre les deux aurait cassé toute
 * création d'entrée qui s'en remet aux défauts.
 */
#[Group('phase1')]
final class CalendarEntryEnumParsingTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unknownValues(): iterable
    {
        yield 'genre inconnu — une période deviendrait un ÉVÉNEMENT' => ['parseKind', 'periode'];
        yield 'statut inconnu — une entrée ignorée redeviendrait ACTIVE' => ['parseStatus', 'archived'];
        yield 'type de période inconnu — le type partirait à NULL (ADR-0002)' => ['parsePeriodType', 'vacances'];
    }

    public function testAnAbsentValueKeepsItsDocumentedDefault(): void
    {
        // Le témoin : sans lui, un parser qui lèverait sur TOUT passerait les tests de refus
        // ci-dessous en paraissant correct, tout en cassant la création nominale.
        self::assertSame(CalendarEntryKind::EVENT, $this->parse('parseKind', null));
        self::assertSame(CalendarEntryStatus::ACTIVE, $this->parse('parseStatus', null));
        self::assertNull($this->parse('parsePeriodType', null));
    }

    public function testAKnownValueIsParsed(): void
    {
        self::assertSame(CalendarEntryKind::PERIOD, $this->parse('parseKind', 'period'));
        self::assertSame(CalendarEntryStatus::IGNORED, $this->parse('parseStatus', 'ignored'));
        self::assertSame(CalendarEntryPeriodType::HOLIDAY, $this->parse('parsePeriodType', 'holiday'));
    }

    #[DataProvider('unknownValues')]
    public function testAPresentButUnknownValueIsRefused(string $parser, string $value): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/n\'est pas une valeur connue/');

        $this->parse($parser, $value);
    }

    public function testTheInputStillAcceptsTheDefaults(): void
    {
        // Garde de cohérence : le DTO ne rend AUCUN de ces trois champs obligatoire — c'est ce
        // qui rend le repli sur défaut légitime, et donc la distinction ci-dessus nécessaire.
        $input = new CalendarEntryInput;
        self::assertNull($input->kind);
        self::assertNull($input->status);
        self::assertNull($input->periodType);
    }

    private function parse(string $method, ?string $value): mixed
    {
        // Les parsers ne touchent ni aux services du constructeur ni à l'état : on contourne
        // le constructeur, dont les dépendances sont finales et immockables (même raison et
        // même geste que `ConstraintStateProcessorTest::invokeUpdate`).
        $processor = new ReflectionClass(CalendarEntryStateProcessor::class)->newInstanceWithoutConstructor();
        $reflected = new ReflectionMethod($processor, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke($processor, $value);
    }
}
