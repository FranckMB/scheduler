<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a new club is created (AuthController::verifyEmail) to fill
 * its institutional data from the FFBB API asynchronously (lot C) — the register
 * flow never blocks on the external call, and a failure is best-effort.
 */
final readonly class PopulateClubFromFfbbMessage
{
    public function __construct(
        private string $clubId,
    ) {}

    public function getClubId(): string
    {
        return $this->clubId;
    }
}
