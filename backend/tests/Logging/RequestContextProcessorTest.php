<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Entity\User;
use App\Logging\RequestContextProcessor;
use App\Service\RequestIdContext;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * P5-11 — le processor qui enrichit chaque log du contexte de corrélation.
 *
 * Garantie double : le trio (request_id, club_id, user_id) est présent quand il
 * est connu ; et JAMAIS de PII (email/nom) — rgpd.md « ids uniquement ».
 */
final class RequestContextProcessorTest extends TestCase
{
    #[Group('phase1')]
    public function testEnrichesWithTheCorrelationTrio(): void
    {
        $context = new RequestIdContext;
        $context->set('11111111-2222-4333-8444-555555555555');

        $request = new Request;
        $request->attributes->set('_club_id', '99999999-8888-4777-8666-555555555555');
        $requestStack = new RequestStack([$request]);

        $user = (new User)->setId('cccccccc-dddd-4eee-8fff-000000000000')->setEmail('coach@example.test');
        $tokenStorage = new TokenStorage;
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));

        $processor = new RequestContextProcessor($context, $requestStack, $tokenStorage);
        $record = $processor($this->record());

        self::assertSame('11111111-2222-4333-8444-555555555555', $record->extra['request_id']);
        self::assertSame('99999999-8888-4777-8666-555555555555', $record->extra['club_id']);
        self::assertSame('cccccccc-dddd-4eee-8fff-000000000000', $record->extra['user_id']);
    }

    #[Group('phase1')]
    public function testNeverLeaksEmailOrName(): void
    {
        $context = new RequestIdContext;
        $context->set('11111111-2222-4333-8444-555555555555');

        $user = (new User)->setId('cccccccc-dddd-4eee-8fff-000000000000')->setEmail('secret@example.test');
        $tokenStorage = new TokenStorage;
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));

        $processor = new RequestContextProcessor($context, new RequestStack, $tokenStorage);
        $record = $processor($this->record());

        // Périmètre STRICT des clés ajoutées : uniquement des ids.
        self::assertSame(['request_id', 'user_id'], array_keys($record->extra));
        // Aucune valeur ne porte l'email de l'utilisateur, quelle que soit la clé.
        self::assertStringNotContainsString('secret@example.test', json_encode($record->extra, \JSON_THROW_ON_ERROR));
    }

    #[Group('phase1')]
    public function testNoContextAddsNothing(): void
    {
        $processor = new RequestContextProcessor(new RequestIdContext, new RequestStack, new TokenStorage);
        $record = $processor($this->record());

        self::assertSame([], $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable,
            'app',
            Level::Info,
            'test message',
        );
    }
}
