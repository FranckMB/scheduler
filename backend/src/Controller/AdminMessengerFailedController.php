<?php

declare(strict_types=1);

namespace App\Controller;

use __PHP_Incomplete_Class;
use DateTimeInterface;
use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/admin/messenger/failed')]
final readonly class AdminMessengerFailedController
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private TransportInterface $failedTransport,
        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,
        #[Autowire(env: 'MESSENGER_FAILURE_TRANSPORT_DSN')]
        private string $failedDsn,
    ) {}

    #[Route(methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT)));

        if ($this->failedTransport instanceof ListableReceiverInterface) {
            ['items' => $items, 'total' => $total] = $this->fromListableTransport($page, $limit);
        } else {
            ['items' => $items, 'total' => $total] = $this->fromRedisStream($page, $limit);
        }

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function fromListableTransport(int $page, int $limit): array
    {
        \assert($this->failedTransport instanceof ListableReceiverInterface);

        // Total via getMessageCount (pas de désérialisation du backlog entier) quand le
        // transport le sait ; sinon repli sur un comptage. extractItem ne DROPant plus rien,
        // items sur toutes les pages == total → pas de page fantôme.
        $total = $this->failedTransport instanceof MessageCountAwareInterface
            ? $this->failedTransport->getMessageCount()
            : iterator_count((function (): iterable {
                yield from $this->failedTransport->all();
            })());

        $offset = ($page - 1) * $limit;
        $items = [];
        $index = 0;
        foreach ($this->failedTransport->all() as $envelope) {
            if ($index >= $offset) {
                $items[] = $this->extractItem($envelope);
            }
            ++$index;
            if (\count($items) >= $limit) {
                break; // page remplie — on ne matérialise que la fenêtre demandée.
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function fromRedisStream(int $page, int $limit): array
    {
        $stream = $this->resolveStreamName();
        $redis = $this->connectRedis();

        $messages = [];
        $start = '-';
        while (true) {
            $range = $redis->xRange($stream, $start, '+', 100);
            if (false === $range || [] === $range) {
                break;
            }
            foreach ($range as $id => $data) {
                $messages[] = ['id' => (string) $id, 'data' => $data];
            }
            $lastId = (string) array_key_last($range);
            $start = $this->incrementStreamId($lastId);
        }

        $offset = ($page - 1) * $limit;
        $slice = \array_slice($messages, $offset, $limit);

        $serializer = new PhpSerializer;
        $items = [];
        foreach ($slice as $message) {
            $item = $this->decodeRedisMessage($message['id'], $message['data'], $serializer);
            if (null !== $item) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'total' => \count($messages)];
    }

    private function resolveStreamName(): string
    {
        $parts = parse_url($this->failedDsn);
        if (!\is_array($parts)) {
            return 'messages';
        }
        $path = $parts['path'] ?? '';
        $trimmed = trim($path, '/');
        if ('' === $trimmed) {
            return 'messages';
        }
        $pathParts = explode('/', $trimmed);

        return $pathParts[0];
    }

    private function connectRedis(): Redis
    {
        $parts = parse_url($this->redisUrl);
        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException('REDIS_URL is invalid.');
        }

        $redis = new Redis;
        $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379));

        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (isset($parts['path']) && '' !== $parts['path'] && '/' !== $parts['path']) {
            $database = ltrim($parts['path'], '/');
            if (ctype_digit($database)) {
                $redis->select((int) $database);
            }
        }

        return $redis;
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<string, mixed>|null
     */
    private function decodeRedisMessage(string $id, array $data, PhpSerializer $serializer): ?array
    {
        $raw = $data['message'] ?? null;
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            // SEC-14 : jamais d'instanciation depuis un payload stocké — on ne veut
            // qu'une CHAÎNE (les objets deviennent __PHP_Incomplete_Class, écartés par
            // le is_string juste dessous).
            $unserialized = @unserialize($raw, ['allowed_classes' => false]); // nosemgrep: php.lang.security.unserialize-use.unserialize-use -- allowed_classes=false : aucune instanciation possible, seul un résultat string est consommé
            if (\is_string($unserialized)) {
                $decoded = json_decode($unserialized, true);
            }
        }
        if (!\is_array($decoded) || !\array_key_exists('body', $decoded)) {
            return null;
        }

        try {
            if (\array_key_exists('headers', $decoded) && \is_array($decoded['headers'])) {
                /** @var array<string, string> $headers */
                $headers = $decoded['headers'];
                $envelope = $serializer->decode([
                    'body' => (string) $decoded['body'],
                    'headers' => $headers,
                ]);
            } else {
                $envelope = $serializer->decode(['body' => (string) $decoded['body']]);
            }
        } catch (Throwable) {
            return null;
        }

        return $this->extractItem($envelope, $id);
    }

    /** @return array<string, mixed>|null */
    /** @return array<string, mixed> */
    private function extractItem(Envelope $envelope, ?string $fallbackId = null): array
    {
        /** @var TransportMessageIdStamp|null $idStamp */
        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        /** @var RedeliveryStamp|null $redeliveryStamp */
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        /** @var ErrorDetailsStamp|null $errorStamp */
        $errorStamp = $envelope->last(ErrorDetailsStamp::class);

        $message = $envelope->getMessage();
        // Message dont la classe n'existe plus dans le code (__PHP_Incomplete_Class) : on ne
        // le DROP pas — sinon items < total et une page fantôme apparaît (revue #296 round 2).
        // On le rend avec un libellé de classe neutre ; les stamps d'erreur restent lisibles.
        $failedAt = null !== $redeliveryStamp
            ? $redeliveryStamp->getRedeliveredAt()->format(DateTimeInterface::ATOM)
            : null;

        return [
            'id' => $idStamp?->getId() ?? $fallbackId ?? 'unknown',
            'class' => $message instanceof __PHP_Incomplete_Class ? '(classe inconnue)' : $message::class,
            'failedAt' => $failedAt,
            'lastErrorMessage' => $errorStamp?->getExceptionMessage() ?? '',
        ];
    }

    private function incrementStreamId(string $id): string
    {
        $pos = strrpos($id, '-');
        if (false === $pos) {
            return $id;
        }
        $prefix = substr($id, 0, $pos);
        $suffix = substr($id, $pos + 1);
        $nextSuffix = (int) $suffix + 1;

        return $prefix . '-' . $nextSuffix;
    }
}
