<?php

declare(strict_types=1);

namespace App\Tests\Queue;

use App\Service\ClubGenerationLock;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class ConcurrentGenerationTest extends WebTestCase
{
    private ?ClubGenerationLock $lock = null;

    public function testAcquireLockForSameClubPreventsConcurrentGeneration(): void
    {
        $clubId = 'club-' . uniqid();

        $token = $this->lock->acquire($clubId, 60);
        self::assertNotNull($token, 'First acquire should return a token');

        $secondToken = $this->lock->acquire($clubId, 60);
        self::assertNull($secondToken, 'Second acquire for same club should fail');

        $this->lock->release($clubId, $token);

        $thirdToken = $this->lock->acquire($clubId, 60);
        self::assertNotNull($thirdToken, 'Acquire after release should succeed');

        $this->lock->release($clubId, $thirdToken);
    }

    public function testDifferentClubsCanAcquireLocksConcurrently(): void
    {
        $clubA = 'club-a-' . uniqid();
        $clubB = 'club-b-' . uniqid();

        $tokenA = $this->lock->acquire($clubA, 60);
        self::assertNotNull($tokenA, 'Club A should acquire lock');

        $tokenB = $this->lock->acquire($clubB, 60);
        self::assertNotNull($tokenB, 'Club B should acquire lock concurrently');

        $this->lock->release($clubA, $tokenA);
        $this->lock->release($clubB, $tokenB);
    }

    public function testReleaseWithWrongTokenDoesNotRemoveLock(): void
    {
        $clubId = 'club-' . uniqid();

        $token = $this->lock->acquire($clubId, 60);
        self::assertNotNull($token);

        $this->lock->release($clubId, 'wrong-token');

        $secondToken = $this->lock->acquire($clubId, 60);
        self::assertNull($secondToken, 'Lock should still be held after release with wrong token');

        $this->lock->release($clubId, $token);
    }

    /**
     * BCK-02: the atomic compare-and-delete must actually delete when the token
     * matches. Guards against a broken Lua script (e.g. wrong KEYS/ARGV index)
     * that would leave the lock held forever — the wrong-token test above would
     * still pass in that case, so this positive half is needed.
     */
    public function testReleaseWithCorrectTokenRemovesLock(): void
    {
        $clubId = 'club-' . uniqid();

        $token = $this->lock->acquire($clubId, 60);
        self::assertNotNull($token);

        $this->lock->release($clubId, $token);

        $reacquired = $this->lock->acquire($clubId, 60);
        self::assertNotNull($reacquired, 'Lock must be free after release with the correct token');

        $this->lock->release($clubId, $reacquired);
    }

    /**
     * P2-2 F2a — la sonde `isGenerating` LIT l'état sans le modifier : elle voit un
     * verrou tenu, ne le pose pas quand il est libre, et un `acquire` reste possible
     * après elle (elle n'a ni acquis ni relâché). C'est le socle du 409 de F2b.
     */
    public function testIsGeneratingReadsTheLockWithoutTouchingIt(): void
    {
        $clubId = 'club-' . uniqid();

        self::assertFalse($this->lock->isGenerating($clubId), 'Aucune génération : la sonde dit false');
        // La sonde ne doit PAS avoir posé le verrou (sinon l'acquire échouerait).
        $token = $this->lock->acquire($clubId, 60);
        self::assertNotNull($token, 'La sonde n\'a pas acquis le verrou : acquire reste possible');

        self::assertTrue($this->lock->isGenerating($clubId), 'Verrou tenu : la sonde dit true');
        // La sonde n'a pas relâché : un second acquire échoue toujours.
        self::assertNull($this->lock->acquire($clubId, 60), 'La sonde n\'a pas relâché le verrou');

        $this->lock->release($clubId, $token);
        self::assertFalse($this->lock->isGenerating($clubId), 'Après release : la sonde dit false');
    }

    protected function setUp(): void
    {
        $this->lock = self::getContainer()->get(ClubGenerationLock::class);
    }
}
