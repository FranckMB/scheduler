<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guard: the custom Symfony #[Route]s (AuthController, ManualEditController,
 * SchoolHolidaysController, PublicHolidaysController) are excluded from API
 * Platform's auto-generated OpenAPI, so CustomRoutesOpenApiFactory injects them.
 * This locks their presence in the schema (and the regenerated
 * specs/courantes/openapi-snapshot.json).
 */
#[Group('phase1')]
final class CustomRoutesOpenApiTest extends KernelTestCase
{
    public function testCustomRoutesAreDocumented(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        $paths = $factory()->getPaths()->getPaths();

        $expected = [
            '/api/register',
            '/api/me',
            '/api/me/password',
            '/api/schedule-slots/{id}/manual-edit/constraint',
            '/api/schedule-slots/{id}/manual-edit/lock',
            '/api/schedule-slots/{id}/manual-edit/one-time',
            '/api/school-holidays',
            '/api/public-holidays',
        ];
        foreach ($expected as $path) {
            self::assertArrayHasKey($path, $paths, $path . ' must be documented in the OpenAPI');
        }
    }
}
