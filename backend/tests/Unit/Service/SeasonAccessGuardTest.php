<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SeasonAccessGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Group('unit')]
final class SeasonAccessGuardTest extends TestCase
{
    public function testThrows409WhenSeasonIsReadonly(): void
    {
        $request = new Request;
        $request->attributes->set('_season_readonly', true);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('This season is archived (read-only).');
        new SeasonAccessGuard()->assertWritable($request);
    }

    public function testAllowsWhenNotReadonly(): void
    {
        $request = new Request;
        $request->attributes->set('_season_readonly', false);

        new SeasonAccessGuard()->assertWritable($request);
        $this->addToAssertionCount(1); // no exception = writable
    }

    public function testAllowsWhenAttributeAbsent(): void
    {
        new SeasonAccessGuard()->assertWritable(new Request);
        $this->addToAssertionCount(1);
    }

    public function testAllowsWhenRequestIsNull(): void
    {
        // Non-HTTP context (CLI/worker) → no request, never blocks.
        new SeasonAccessGuard()->assertWritable(null);
        $this->addToAssertionCount(1);
    }
}
