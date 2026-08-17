<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MailFrom;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P5-15 — l'expéditeur des mails a UNE maison : `MailFrom`, alimenté par
 * `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`.
 *
 * Le test qui compte est le second : il interdit qu'une adresse d'expéditeur
 * revienne en dur dans `src/`. C'est exactement ce qui avait rendu le changement
 * de domaine coûteux — treize littéraux à retrouver un par un, dont quatre au
 * milieu d'un contrôleur de 900 lignes. Un `->from('…')` littéral rouvre la
 * chasse : il échoue ici, en nommant le fichier et la ligne.
 */
#[Group('phase1')]
final class MailFromTest extends TestCase
{
    public function testAddressCarriesTheConfiguredNameAndAddress(): void
    {
        $from = new MailFrom('envoi@exemple.test', 'Marque')->address();

        self::assertSame('envoi@exemple.test', $from->getAddress());
        self::assertSame('Marque', $from->getName());
    }

    public function testNoSenderAddressIsHardCodedOutsideMailFrom(): void
    {
        $offenders = [];
        $root = \dirname(__DIR__, 3) . '/src';
        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $path => $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension() || str_ends_with($path, 'Service/MailFrom.php')) {
                continue;
            }

            $contents = file_get_contents($path);
            self::assertIsString($contents);

            foreach (explode("\n", $contents) as $number => $line) {
                // Un expéditeur littéral : `->from('quelqu-un@domaine')`, ou une
                // constante qui porte une adresse (le patron d'avant P5-15).
                if (1 === preg_match('/->from\(\s*[\'"]/', $line) || 1 === preg_match('/const\s+\w*FROM\w*\s*=\s*[\'"][^\'"]*@/', $line)) {
                    $offenders[] = \sprintf('%s:%d', str_replace($root . '/', '', $path), $number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Expéditeur codé en dur : passer par MailFrom (MAIL_FROM_ADDRESS/MAIL_FROM_NAME).');
    }
}
