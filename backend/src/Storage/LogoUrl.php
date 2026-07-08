<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The public serve URL for a club logo, with a content-hash cache-buster so a
 * new upload isn't masked by the browser cache (the path is otherwise stable).
 * One home so the upload controller and the fixtures never drift apart.
 */
final class LogoUrl
{
    public static function build(string $clubId, string $bytes): string
    {
        return \sprintf('/api/clubs/%s/logo?v=%s', $clubId, substr(md5($bytes), 0, 8));
    }
}
