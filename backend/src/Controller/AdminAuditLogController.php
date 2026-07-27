<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/audit-log')]
final readonly class AdminAuditLogController
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ManagerRegistry $registry,
    ) {}

    #[Route('', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $page = $this->positiveInt($request->query->get('page', '1'), 'page');
            $limit = $this->clampLimit($this->positiveInt($request->query->get('limit', (string) self::DEFAULT_LIMIT), 'limit'));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $actor = $request->query->get('actor');
        $route = $request->query->get('route');
        $since = $request->query->get('since');

        if (null !== $actor && !preg_match(self::UUID_PATTERN, $actor)) {
            return new JsonResponse(['error' => 'Invalid actor UUID.'], 400);
        }

        if (null !== $since) {
            $sinceDate = DateTimeImmutable::createFromFormat('Y-m-d', $since);
            if (false === $sinceDate || $sinceDate->format('Y-m-d') !== $since) {
                return new JsonResponse(['error' => 'Invalid since date. Expected ISO date (YYYY-MM-DD).'], 400);
            }
        }

        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        $where = ['1 = 1'];
        $params = [];

        if (null !== $actor) {
            $where[] = 'a.super_admin_id = :actor';
            $params['actor'] = $actor;
        }

        if (null !== $route) {
            $where[] = 'a.route LIKE :route';
            $params['route'] = '%' . $route . '%';
        }

        if (null !== $since) {
            $where[] = 'a.occurred_at >= :since';
            $params['since'] = $since . ' 00:00:00';
        }

        $whereSql = implode(' AND ', $where);

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM admin_audit_log a WHERE ' . $whereSql,
            $params,
        );

        $offset = ($page - 1) * $limit;

        $rows = $connection->fetchAllAssociative(
            'SELECT a.id, a.super_admin_id AS actor_id, s.email AS actor_email, a.route, a.details AS context, a.status_code AS status, a.occurred_at AS created_at '
            . 'FROM admin_audit_log a '
            . 'LEFT JOIN super_admin s ON s.id = a.super_admin_id '
            . 'WHERE ' . $whereSql . ' '
            . 'ORDER BY a.occurred_at DESC, a.id DESC '
            . 'LIMIT :limit OFFSET :offset',
            [...$params, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $items = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'actorId' => null !== $row['actor_id'] ? (string) $row['actor_id'] : null,
            'actorEmail' => null !== $row['actor_email'] ? (string) $row['actor_email'] : null,
            'route' => null !== $row['route'] ? (string) $row['route'] : null,
            'context' => json_decode((string) $row['context'], true) ?? [],
            'status' => null !== $row['status'] ? (int) $row['status'] : null,
            'createdAt' => (string) $row['created_at'],
        ], $rows);

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $count,
                'pages' => (int) ceil($count / $limit),
            ],
        ]);
    }

    private function positiveInt(string $value, string $name): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException("Invalid {$name}: must be a positive integer.");
        }

        return (int) $value;
    }

    private function clampLimit(int $limit): int
    {
        return min($limit, self::MAX_LIMIT);
    }
}
