<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Dev/default logo storage: one file per club on local disk. Prod swaps this
 * alias (services.yaml) for an object-storage implementation without touching
 * callers.
 */
final class LocalLogoStorage implements LogoStorage
{
    public function __construct(private readonly string $dir) {}

    public function store(string $clubId, string $bytes): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create logo storage dir: {$this->dir}");
        }
        file_put_contents($this->path($clubId), $bytes);
    }

    public function read(string $clubId): ?string
    {
        $path = $this->path($clubId);
        if (!is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);

        return false === $bytes ? null : $bytes;
    }

    public function delete(string $clubId): void
    {
        $path = $this->path($clubId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $clubId): string
    {
        // basename guards against path traversal (clubId is a UUID anyway).
        return rtrim($this->dir, '/') . '/' . basename($clubId);
    }
}
