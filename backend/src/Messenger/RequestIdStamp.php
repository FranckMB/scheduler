<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Porte l'id de corrélation (X-Request-Id) sur l'enveloppe d'un message : la
 * requête HTTP qui a enfilé le message et le worker qui le traite partagent
 * ainsi le MÊME request_id dans leurs logs (front→backend→bus→engine).
 */
final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(public string $requestId) {}
}
