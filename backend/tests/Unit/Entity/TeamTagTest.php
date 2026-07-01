<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\TeamTag;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TeamTagTest extends TestCase
{
    public function testUuidGeneratedOnConstruct(): void
    {
        $tag = new TeamTag;
        self::assertNotEmpty($tag->getId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tag->getId());
    }

    public function testIsSystemDefaultFalse(): void
    {
        $tag = new TeamTag;
        self::assertFalse($tag->getIsSystem());
        self::assertFalse($tag->isIsSystem());
    }

    public function testSettersAndGetters(): void
    {
        $tag = new TeamTag;

        $tag->setClubId('club-1');
        self::assertSame('club-1', $tag->getClubId());

        $tag->setName('U15');
        self::assertSame('U15', $tag->getName());

        $tag->setColor('#FF0000');
        self::assertSame('#FF0000', $tag->getColor());

        $tag->setIsSystem(true);
        self::assertTrue($tag->getIsSystem());
    }

    public function testFluentInterface(): void
    {
        $tag = new TeamTag;
        self::assertSame($tag, $tag->setName('Test'));
        self::assertSame($tag, $tag->setClubId('c1'));
    }

    public function testTouchUpdatedAt(): void
    {
        $tag = new TeamTag;
        $originalUpdatedAt = $tag->getUpdatedAt();

        usleep(1000);
        $tag->touchUpdatedAt();

        self::assertGreaterThan($originalUpdatedAt, $tag->getUpdatedAt());
    }
}
