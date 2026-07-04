<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use ArrayObject;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * G4/G5: `/api/register`, `/api/me` (AuthController) and the three
 * `/api/schedule-slots/{id}/manual-edit/*` routes (ManualEditController) are
 * plain Symfony `#[Route]`s, not API Platform operations, so they were absent
 * from the generated OpenAPI (and the `specs/courantes/openapi-snapshot.json`).
 * This decorator injects their paths so `/api/docs` and the snapshot document
 * the full contract. The endpoints themselves are unchanged.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class CustomRoutesOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/register', new PathItem(post: new Operation(
            operationId: 'postApiRegister',
            tags: ['Auth'],
            responses: [
                '201' => $this->jsonResponse('Registered — returns a JWT and the membership status', [
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string'],
                        'membershipStatus' => ['type' => 'string', 'enum' => ['none', 'pending', 'active']],
                        'user' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                        ]],
                    ],
                ]),
                '400' => new Response('Validation error'),
                '409' => new Response('Email already registered'),
                '429' => new Response('Too many attempts (rate limited)'),
            ],
            summary: 'Register a user and create or join a club by ARA / FFBB code',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['email', 'password', 'firstName', 'lastName', 'ara'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'minLength' => 8],
                    'firstName' => ['type' => 'string'],
                    'lastName' => ['type' => 'string'],
                    'ara' => ['type' => 'string', 'description' => 'FFBB club code — 3-20 uppercase alphanumeric'],
                    'club_name' => ['type' => 'string', 'description' => 'Required only when the ARA creates a new club (snake_case)'],
                ],
            ]),
        )));

        $paths->addPath('/api/me', new PathItem(get: new Operation(
            operationId: 'getApiMe',
            tags: ['Auth'],
            responses: [
                '200' => new Response('The authenticated user + its club context', new ArrayObject([
                    'application/json' => ['schema' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'firstName' => ['type' => 'string'],
                        'lastName' => ['type' => 'string'],
                        'membershipStatus' => ['type' => 'string', 'enum' => ['none', 'pending', 'active']],
                        'role' => ['type' => 'string', 'nullable' => true],
                        'club' => ['type' => 'object', 'nullable' => true, 'properties' => [
                            'id' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'onboardingCompleted' => ['type' => 'boolean'],
                            'logoUrl' => ['type' => 'string', 'nullable' => true],
                            'accentColor' => ['type' => 'string', 'nullable' => true],
                            'accentPalette' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'string']],
                        ]],
                        'baselineScheduleId' => ['type' => 'string', 'nullable' => true],
                        'hasGenerated' => ['type' => 'boolean'],
                    ]]],
                ])),
                '401' => new Response('Unauthorized'),
            ],
            summary: 'Hydrate the authenticated user and its active club context',
        ), patch: new Operation(
            operationId: 'patchApiMe',
            tags: ['Auth'],
            responses: [
                '200' => $this->jsonResponse('Updated profile', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'firstName' => ['type' => 'string'],
                        'lastName' => ['type' => 'string'],
                    ],
                ]),
                '400' => new Response('Validation error (empty name / invalid email)'),
                '409' => new Response('E-mail already in use'),
            ],
            summary: 'Update the connected user profile (name / e-mail)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'properties' => [
                    'firstName' => ['type' => 'string'],
                    'lastName' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                ],
            ]),
        )));

        $paths->addPath('/api/me/password', new PathItem(post: new Operation(
            operationId: 'postApiMePassword',
            tags: ['Auth'],
            responses: [
                '200' => new Response('Password changed'),
                '400' => new Response('Wrong current password or new password too short'),
            ],
            summary: 'Change the connected user password (current password required)',
            requestBody: $this->jsonBody([
                'type' => 'object',
                'required' => ['currentPassword', 'newPassword'],
                'properties' => [
                    'currentPassword' => ['type' => 'string'],
                    'newPassword' => ['type' => 'string', 'minLength' => 8],
                ],
            ]),
        )));

        foreach ($this->manualEditPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        return $openApi;
    }

    /**
     * @return array<string, PathItem>
     */
    private function manualEditPaths(): array
    {
        $messageResponse = static fn (string $description): Response => new Response(
            $description,
            new ArrayObject(['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['message' => ['type' => 'string']],
            ]]]),
        );

        return [
            '/api/schedule-slots/{id}/manual-edit/constraint' => new PathItem(post: new Operation(
                operationId: 'postManualEditConstraint',
                tags: ['ManualEdit'],
                responses: [
                    '201' => $this->jsonResponse('Constraint created — returns its id', [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'constraintId' => ['type' => 'string'],
                        ],
                    ]),
                    '400' => new Response('Missing/invalid field'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Schedule is validated (read-only)'),
                ],
                summary: 'Attach a manual constraint to a schedule slot',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['type'],
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'createdBy' => ['type' => 'string'],
                    ],
                ]),
            )),
            '/api/schedule-slots/{id}/manual-edit/lock' => new PathItem(post: new Operation(
                operationId: 'postManualEditLock',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $messageResponse('Lock applied'),
                    '400' => new Response('Missing/invalid lockLevel'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Schedule is validated (read-only)'),
                ],
                summary: 'Set the lock level of a schedule slot',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'required' => ['lockLevel'],
                    'properties' => ['lockLevel' => ['type' => 'string', 'enum' => ['NONE', 'SOFT', 'HARD']]],
                ]),
            )),
            '/api/schedule-slots/{id}/manual-edit/one-time' => new PathItem(post: new Operation(
                operationId: 'postManualEditOneTime',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $messageResponse('One-time update applied'),
                    '400' => new Response('Invalid body'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Conflict (validated schedule or overlapping slot)'),
                ],
                summary: 'Apply a one-time (single-occurrence) start-time change to a slot',
                requestBody: $this->jsonBody([
                    'type' => 'object',
                    'properties' => ['startTime' => ['type' => 'string', 'example' => '18:30']],
                ]),
            )),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function jsonBody(array $schema): RequestBody
    {
        return new RequestBody(content: new ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function jsonResponse(string $description, array $schema): Response
    {
        return new Response($description, new ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }
}
