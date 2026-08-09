<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P4-46 — « quel jour est-on pour ce club ? » ne se réécrit pas inline.
 *
 * ⚑ Au moment de créer le foyer, la logique existait déjà TROIS fois copiées — dont une
 * (`PeriodReminderCommand`) que le balayage initial avait RATÉE : c'est ce garde, écrit en
 * la cherchant, qui l'a trouvée. Deux consommateurs l'ignoraient par ailleurs, et l'un
 * fermait le lien public des doléances un jour trop tôt pour un club antillais.
 *
 * La règle : `Club::getTimezone()` ne se lit que dans `ClubDay` (le foyer) et
 * `ClubResource` (l'exposition API). Toute autre lecture est une quatrième copie en
 * gestation — le calcul du « jour du club » doit passer par le foyer.
 */
#[Group('phase1')]
final class ClubDayIsNotRebuiltTest extends TestCase
{
    private const array ALLOWED = [
        'Entity/Club.php',       // le champ lui-même
        'Service/ClubDay.php',   // le foyer
        'ApiResource/ClubResource.php', // exposition en lecture, pas un calcul
    ];

    public function testNobodyReadsTheTimezoneOutsideTheFocus(): void
    {
        $offenders = [];
        $root = realpath(__DIR__ . '/../../../src');
        self::assertIsString($root);

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr((string) $file->getRealPath(), \strlen($root) + 1);
            if (\in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            $source = (string) file_get_contents((string) $file->getRealPath());
            if (str_contains($source, 'getTimezone()')) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);
        self::assertSame([], $offenders, \sprintf(
            "Ces fichiers lisent Club::getTimezone() hors du foyer :\n  - %s\n\n"
            . "Le calcul du « jour du club » vit dans ClubDay — la logique a déjà été trouvée\n"
            . "copiée TROIS fois (dont une que le balayage initial avait ratée), et l'une des\n"
            . 'copies manquantes fermait un lien public un jour trop tôt pour un club antillais.',
            implode("\n  - ", $offenders),
        ));
    }
}
