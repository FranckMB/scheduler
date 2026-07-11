<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\AuthController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * RGPD PR-5 non-regression (axe auth & memberships) : consentement au register.
 *
 * (a) sans consentement → 400 (validation payload-only, enumeration-safe A3 :
 *     la réponse ne dépend jamais de l'existence de l'email) ;
 * (b) avec consentement → 202 + preuve stockée (termsAcceptedAt + version).
 */
#[Group('phase1')]
#[Group('integration')]
final class ConsentTest extends WebTestCase
{
    private KernelBrowser $client;

    public function testRegisterWithoutConsentIsRejected(): void
    {
        $this->register(consent: false);
        self::assertResponseStatusCodeSame(400);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('CGU', $body['error']);
    }

    public function testRegisterWithConsentStoresTheProof(): void
    {
        $email = $this->register(consent: true);
        self::assertResponseStatusCodeSame(202);

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getTermsAcceptedAt(), 'preuve de consentement horodatée');
        self::assertSame(AuthController::TERMS_VERSION, $user->getTermsVersion(), 'version des textes acceptés');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function register(bool $consent): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'cons' . substr(md5(uniqid('', true)), 0, 8);
        $email = $suffix . '@test.fr';
        $payload = [
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Con', 'lastName' => 'Sent',
            'ara' => strtoupper($suffix), 'club_name' => 'Club Consent',
        ];
        if ($consent) {
            $payload['consent'] = true;
        }
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        return $email;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
