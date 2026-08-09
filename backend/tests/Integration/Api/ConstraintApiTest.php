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
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
final class ConstraintApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    /** @return iterable<string, array{string, string}> */
    public static function invalidEnumValues(): iterable
    {
        yield 'family' => ['family', 'DAYS'];
        yield 'scope' => ['scope', 'CLUBS'];
        yield 'ruleType' => ['ruleType', 'HARDER'];
    }

    public function testCreateConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'No Saturday Practice',
            'description' => 'Teams cannot practice on Saturdays',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'isActive' => true,
            'sortOrder' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertSame('No Saturday Practice', $data['name']);
        self::assertSame('CLUB', $data['scope']);
        self::assertSame('DAY', $data['family']);
        self::assertSame('HARD', $data['ruleType']);
        self::assertSame(['forbiddenDays' => [6]], $data['config']);
        self::assertTrue($data['isActive']);
        self::assertSame(1, $data['sortOrder']);
    }

    /**
     * AUD-BCK-12 — axe *constraint semantics* : une valeur d'enum fautive est REFUSÉE,
     * jamais silencieusement corrigée.
     *
     * Le finding annonçait un repli silencieux (`family` fautif → TIME) atteignable par
     * l'API. Mesure faite, il ne l'est pas : `ConstraintInput` porte `Assert\Choice` sur
     * les trois champs et le validateur rend 422 avant le processeur. Ce test épingle la
     * garantie qui compte — celle du bord — plutôt que le détail d'implémentation qui la
     * rend vraie.
     *
     * ⚑ Ce qui serait perdu sans elle : « DAYS » deviendrait une contrainte TIME,
     * enregistrée et envoyée au solveur. Le gestionnaire poserait une règle de JOUR et
     * obtiendrait une règle d'HEURE — honorée, cohérente, et fausse. C'est le motif
     * « déclaré ≠ effectif » que SEC-13 a tué sur `config`.
     *
     * ⚠ Ce test tourne dans `unit-tests`, PAS dans le job `blocking-tests` (§4) : il
     * bloque le merge via le contexte requis « Unit Tests », mais ne gate pas
     * `build-docker` et son verdict tombe après celui du gate.
     */
    #[DataProvider('invalidEnumValues')]
    public function testAnInvalidEnumValueIsRefusedNeverSilentlyCoerced(string $field, string $value): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $payload = [
            'name' => 'Typo',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'isActive' => true,
            'sortOrder' => 1,
        ];
        $payload[$field] = $value;

        $client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode(), \sprintf(
            'Une valeur « %s » sur %s doit être REFUSÉE. Si elle passe, elle a été corrigée en silence : '
            . 'la contrainte enregistrée ne sera pas celle que le gestionnaire a demandée.',
            $value,
            $field,
        ));
        self::assertStringContainsString($field, (string) $client->getResponse()->getContent(), 'La réponse doit NOMMER le champ fautif — sans quoi la typo se corrige à l\'aveugle.');
    }

    /**
     * AUD-BCK-13 — un gymnase inconnu dans le `config` est REFUSÉ à l'écriture.
     *
     * ⚑ Mesuré côté moteur avant d'écrire le correctif : un `forcedVenueId` qui ne
     * correspond à aucun gymnase fait forcer à 0 TOUTES les affectations de l'équipe
     * (« quand un gymnase est imposé, tous les autres sont fixés à 0 » — et aucun n'est
     * celui-là). Le solve rend `completed` avec **zéro créneau**, l'équipe absente.
     *
     * ⚠ Et le diagnostic désigne les mauvaises causes : « déjà occupés par des équipes plus
     * prioritaires, ou en conflit avec ses contraintes (coach indisponible, gymnase fermé,
     * jour interdit) ». Quatre pistes, toutes fausses. Le gestionnaire chercherait une
     * indisponibilité qui n'existe pas.
     *
     * Le refus à l'écriture supprime l'état plutôt que d'espérer qu'un diagnostic le
     * rattrape — d'autant que celui-ci, ici, ment.
     */
    public function testAConstraintCannotImposeAVenueThatDoesNotExist(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Impose un gymnase fantôme',
            'scope' => 'TEAM',
            'scopeTargetId' => '11111111-1111-4111-8111-111111111111',
            'family' => 'FACILITY',
            'ruleType' => 'HARD',
            'config' => ['forcedVenueId' => '00000000-0000-4000-8000-000000000000'],
            'isActive' => true,
            'sortOrder' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Un gymnase inexistant doit être refusé : la contrainte rendrait l\'équipe impossible à placer, et le diagnostic du moteur accuserait autre chose.');
        self::assertStringContainsString('forcedVenueId', (string) $client->getResponse()->getContent(), 'La réponse doit NOMMER la clé fautive.');
    }

    public function testListConstraints(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $this->createConstraint('Constraint A', 'CLUB');
        $this->createConstraint('Constraint B', 'TEAM');

        $client->request('GET', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('member', $data);
        self::assertCount(2, $data['member']);
    }

    public function testGetConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('Test Constraint', 'CLUB');

        $client->request('GET', \sprintf('/api/constraints/%s', $constraint->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($constraint->getId(), $data['id']);
        self::assertSame('Test Constraint', $data['name']);
        self::assertSame('CLUB', $data['scope']);
    }

    public function testUpdateConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('Original Name', 'CLUB');

        $client->request('PUT', \sprintf('/api/constraints/%s', $constraint->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6, 7]],
            'isActive' => false,
            'sortOrder' => 5,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Updated Name', $data['name']);
        self::assertSame('Updated description', $data['description']);
        self::assertFalse($data['isActive']);
        self::assertSame(5, $data['sortOrder']);
        self::assertSame([6, 7], $data['config']['forbiddenDays']);
    }

    public function testDeleteConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('To Delete', 'CLUB');
        $constraintId = $constraint->getId();

        $client->request('DELETE', \sprintf('/api/constraints/%s', $constraintId), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(204);

        $deleted = $this->em->getRepository(Constraint::class)->find($constraintId);
        self::assertNull($deleted);
    }

    public function testUnauthorized(): void
    {
        $this->client->request('GET', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $this->club = $this->createClub();
        $this->user = $this->createUser();
        $this->createClubUser($this->club, $this->user);
        $this->season = $this->createSeason($this->club);
    }

    private function createClub(): Club
    {
        $club = new Club;
        $club->setName('Test Club ' . uniqid());
        $club->setSlug('test-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function createUser(): User
    {
        $user = new User;
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'Password123!'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClubUser(Club $club, User $user): void
    {
        $clubUser = new ClubUser;
        $clubUser->setClubId($club->getId());
        $clubUser->setUserId($user->getId());
        $clubUser->setRole('admin');
        $clubUser->setIsActive(true);

        $this->em->persist($clubUser);
        $this->em->flush();
    }

    private function createSeason(Club $club): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function createConstraint(string $name, string $scope): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName($name);
        $constraint->setScope(ConstraintScope::from($scope));
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['forbiddenDays' => [6]]);

        if ('CLUB' !== $scope) {
            $constraint->setScopeTargetId('11111111-1111-1111-1111-111111111111');
        }

        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint;
    }
}
