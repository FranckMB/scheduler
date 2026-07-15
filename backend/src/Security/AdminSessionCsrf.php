<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/** Double-submit guard for the stateful super-admin firewall. */
final class AdminSessionCsrf
{
    private const SESSION_KEY = 'admin_csrf_token';

    public function issue(Request $request): string
    {
        $token = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function current(Request $request): ?string
    {
        $token = $request->getSession()->get(self::SESSION_KEY);

        return \is_string($token) && '' !== $token ? $token : null;
    }

    public function isValid(Request $request): bool
    {
        $expected = $this->current($request);
        $provided = $request->headers->get('X-CSRF-Token');

        return null !== $expected && \is_string($provided) && hash_equals($expected, $provided);
    }
}
