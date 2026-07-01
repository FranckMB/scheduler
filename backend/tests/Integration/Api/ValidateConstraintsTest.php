<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BW3 — the pre-solve gate surfaces gross constraint errors before generation.
 */
#[Group('integration')]
final class ValidateConstraintsTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    private Season $season;

    public function testCleanConstraintsAreValid(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['valid']);
    }

    public function testContradictoryHardTimeConstraintsAreRejected(): void
    {
        // Two CLUB HARD TIME rules: "not after 10:00" vs "not before 12:00" — impossible.
        $this->constraint(['maxStartTime' => '10:00']);
        $this->constraint(['minStartTime' => '12:00']);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['conflicts']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Constraint Test Club');
        $this->club->setSlug('constraint-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('CST' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('cst' . $uid . '@test.com');
        $this->user->setFirstName('Cst');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus('active');
        $this->em->persist($this->season);

        $this->em->flush();
    }

    /** @param array<string, mixed> $config */
    private function constraint(array $config): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('rule');
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }
}
