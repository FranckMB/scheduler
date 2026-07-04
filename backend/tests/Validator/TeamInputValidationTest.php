<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Dto\TeamInput;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * BCK-05: TeamInput validation was sparse — negative/zero sessionsPerWeek, an
 * out-of-range matchDay (e.g. 99), and a missing (required) sportCategoryId all
 * passed straight through to the solver / a DB 500. These guard the constraints.
 */
#[Group('phase1')]
final class TeamInputValidationTest extends KernelTestCase
{
    public function testValidTeamPasses(): void
    {
        $input = new TeamInput;
        $input->name = 'U13M1';
        $input->sportCategoryId = 'cat-1';
        $input->sessionsPerWeek = 2;
        $input->matchDay = 6;

        self::assertSame([], $this->violations($input, 'create'));
    }

    public function testNegativeOrZeroSessionsPerWeekIsRejected(): void
    {
        $input = new TeamInput;
        $input->name = 'x';
        $input->sessionsPerWeek = 0;
        self::assertContains('sessionsPerWeek', $this->violations($input));

        $input->sessionsPerWeek = -1;
        self::assertContains('sessionsPerWeek', $this->violations($input));
    }

    public function testMatchDayOutOfRangeIsRejected(): void
    {
        $input = new TeamInput;
        $input->name = 'x';
        $input->matchDay = 99;
        self::assertContains('matchDay', $this->violations($input));

        $input->matchDay = -1;
        self::assertContains('matchDay', $this->violations($input));
    }

    public function testMissingSportCategoryRejectedOnCreateOnly(): void
    {
        $input = new TeamInput;
        $input->name = 'x';
        // No sportCategoryId.
        self::assertContains('sportCategoryId', $this->violations($input, 'create'), 'required on create');
        self::assertNotContains('sportCategoryId', $this->violations($input), 'partial PUT (Default group) must not require it');
    }

    private function validator(): ValidatorInterface
    {
        self::bootKernel();

        return self::getContainer()->get(ValidatorInterface::class);
    }

    /** @return list<string> violated property paths */
    private function violations(TeamInput $input, ?string $group = null): array
    {
        $list = $this->validator()->validate($input, null, null !== $group ? [$group] : null);
        $paths = [];
        foreach ($list as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
