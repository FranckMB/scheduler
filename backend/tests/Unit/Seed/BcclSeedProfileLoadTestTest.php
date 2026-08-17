<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\BcclSeedProfile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le profil `loadTest($i)` du harness de charge : N identités indépendantes,
 * chacune disjointe des deux profils historiques (dev/demo) sur les clés
 * uniques (slug, code FFBB, email gestionnaire), et jamais un vrai club.
 */
#[Group('phase1')]
final class BcclSeedProfileLoadTestTest extends TestCase
{
    public function testEachIndexHasDistinctUniqueKeys(): void
    {
        $slugs = [];
        $codes = [];
        $emails = [];

        for ($i = 1; $i <= 99; ++$i) {
            $profile = BcclSeedProfile::loadTest($i);
            $slugs[] = $profile->clubSlug;
            $codes[] = $profile->ffbbCode;
            $emails[] = $profile->managerEmail;
        }

        self::assertCount(99, array_unique($slugs), 'les slugs des 99 clubs de charge sont tous distincts');
        self::assertCount(99, array_unique($codes), 'les codes FFBB des 99 clubs de charge sont tous distincts');
        self::assertCount(99, array_unique($emails), 'les emails gestionnaire des 99 clubs de charge sont tous distincts');
    }

    public function testFfbbCodeIsWellFormedAndInFictionalRange(): void
    {
        self::assertSame('ARA9999001', BcclSeedProfile::loadTest(1)->ffbbCode);
        self::assertSame('ARA9999009', BcclSeedProfile::loadTest(9)->ffbbCode);
        self::assertSame('ARA9999010', BcclSeedProfile::loadTest(10)->ffbbCode);
        self::assertSame('ARA9999099', BcclSeedProfile::loadTest(99)->ffbbCode);
    }

    public function testSlugAndEmailFollowTheIndex(): void
    {
        $profile = BcclSeedProfile::loadTest(7);
        self::assertSame('Club Charge 7', $profile->clubName);
        self::assertSame('club-charge-7', $profile->clubSlug);
        self::assertSame('charge-7@amateo.local', $profile->managerEmail);
    }

    public function testDisjointFromDevAndDemoProfiles(): void
    {
        $dev = BcclSeedProfile::dev();
        $demo = BcclSeedProfile::demo('a-strong-password');

        for ($i = 1; $i <= 99; ++$i) {
            $profile = BcclSeedProfile::loadTest($i);

            self::assertNotSame($dev->ffbbCode, $profile->ffbbCode, 'jamais le code FFBB du BCCL dev');
            self::assertNotSame($demo->ffbbCode, $profile->ffbbCode, 'jamais le code FFBB du club démo (ARA9999999)');
            self::assertNotSame($dev->clubSlug, $profile->clubSlug);
            self::assertNotSame($demo->clubSlug, $profile->clubSlug);
            self::assertNotSame($dev->managerEmail, $profile->managerEmail);
            self::assertNotSame($demo->managerEmail, $profile->managerEmail);
        }

        // Le ARA9999999 de demo() (100e du motif ARA99990NN étendu) ne peut pas
        // être atteint : le garde borne l'index à 99.
        self::assertSame('ARA9999999', $demo->ffbbCode);
    }

    public function testRejectsOutOfRangeIndexes(): void
    {
        foreach ([0, 100, -1] as $bad) {
            try {
                BcclSeedProfile::loadTest($bad);
                self::fail(\sprintf('loadTest(%d) aurait dû être rejeté', $bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testIsNotFlaggedAsDemoAndUsesFictionalCoaches(): void
    {
        $profile = BcclSeedProfile::loadTest(1);

        self::assertFalse($profile->isDemo, 'un club de charge n\'est pas un club de démonstration');
        self::assertFalse($profile->seedLogo, 'pas de logo BCCL sur un club de charge (RGPD/identité)');
        self::assertNotNull($profile->coachNames, 'coachs fictifs imposés (RGPD)');
        self::assertGreaterThanOrEqual(12, \strlen($profile->managerPassword), 'mot de passe gestionnaire >= 12 chars');
    }
}
