<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Club logo bytes storage. One implementation per target (local disk in dev,
 * object storage in prod) — swap the service alias in services.yaml, no caller
 * change. Keyed by clubId; mime is detected on read.
 */
interface LogoStorage
{
    public function store(string $clubId, string $bytes): void;

    /** Raw bytes, or null if the club has no stored logo. */
    public function read(string $clubId): ?string;

    public function delete(string $clubId): void;
}
