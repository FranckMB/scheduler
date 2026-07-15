<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function testRfc6238Sha1VectorAndEncryptedVerification(): void
    {
        $service = new TotpService('test-app-secret');
        // RFC 6238 secret "12345678901234567890" in Base32; vector is 8 digits,
        // while the product deliberately uses Google Authenticator's 6 digits.
        self::assertSame('287082', $service->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));

        $secret = $service->generateSecret();
        $encrypted = $service->encrypt($secret);
        self::assertNotSame($secret, $encrypted);
        self::assertTrue($service->verifyEncrypted($encrypted, $service->code($secret, 1_700_000_000), 1_700_000_000));
        self::assertFalse($service->verifyEncrypted($encrypted, '000000', 1_700_000_000));
        self::assertFalse($service->verifyEncrypted($encrypted, 'code: ' . $service->code($secret, 1_700_000_000), 1_700_000_000));
    }
}
