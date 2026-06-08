<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 stub — verifies contract_version file exists.
 *
 * Phase 2: will verify JSON from ScheduleConstraintBuilder validates
 * against Pydantic ScheduleInputSchema.
 * Phase 4: will validate real ScheduleConstraintBuilder output.
 */
final class ContractSchemaTest extends TestCase
{
    private const CONTRACT_VERSION_FILE = __DIR__ . '/../../../../engine/CONTRACT_VERSION';

    /** @group phase1 */
    public function testContractVersionFileExists(): void
    {
        self::assertFileExists(self::CONTRACT_VERSION_FILE, 'engine/CONTRACT_VERSION must exist');
    }

    /** @group phase1 */
    public function testContractVersionIsNotEmpty(): void
    {
        $content = file_get_contents(self::CONTRACT_VERSION_FILE);
        self::assertNotEmpty($content, 'CONTRACT_VERSION must not be empty');
    }

    /** @group phase2 */
    public function testStubJsonValidatesAgainstPydantic(): void
    {
        self::markTestSkipped('Phase 2: requires engine container with Pydantic');
    }
}
