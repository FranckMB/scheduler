<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La porte API des règles implicites « bien-être » (P2-28 PR 2) : collection RÉSOLUE (toujours
 * 4), upsert par `ruleKey`, bornes de seuil et couple règle↔seuil refusés en 422 français, DELETE
 * = réinitialiser.
 */
#[Group('integration')]
final class ImplicitRuleSettingApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCollectionAlwaysResolvesTheFourRulesWithDefaults(): void
    {
        [$user] = $this->seed();

        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        $rules = $this->members();
        self::assertSame(
            ['ageAscending', 'coachRestDay', 'maxConsecutiveSessions', 'salarieDistribution'],
            $this->sortedKeys($rules),
            'la collection résout TOUJOURS les 4 règles, défauts compris',
        );
        foreach ($rules as $rule) {
            self::assertSame('HARD', $rule['intensity'], 'sans réglage, tout est au défaut HARD');
            self::assertTrue($rule['isDefault']);
        }
    }

    public function testUpsertStoresAndResolvedGetReflectsIt(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth);
        $coachRest = $this->member('coachRestDay');
        self::assertSame('PREFERRED', $coachRest['intensity']);
        self::assertSame(3, $coachRest['minRestDays']);
        self::assertFalse($coachRest['isDefault']);
    }

    public function testDeleteResetsToDefault(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/implicit_rule_settings/maxConsecutiveSessions', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'maxConsecutive' => 5], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->request('DELETE', '/api/implicit_rule_settings/maxConsecutiveSessions', [], [], $auth);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth);
        $rule = $this->member('maxConsecutiveSessions');
        self::assertSame('HARD', $rule['intensity'], 'après réinitialisation, retour au défaut');
        self::assertSame(3, $rule['maxConsecutive']);
        self::assertTrue($rule['isDefault']);
    }

    public function testAThresholdOutOfBoundsIs422(): void
    {
        [$user] = $this->seed();

        // minRestDays borné 1-4 : 9 est hors bornes.
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'HARD', 'minRestDays' => 9], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAThresholdOnTheWrongRuleIs422(): void
    {
        [$user] = $this->seed();

        // ageAscending n'a pas de seuil : lui envoyer minRestDays est un contresens refusé.
        $this->client->request('PUT', '/api/implicit_rule_settings/ageAscending', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 2], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnknownRuleKeyIs404(): void
    {
        [$user] = $this->seed();

        $this->client->request('PUT', '/api/implicit_rule_settings/notARule', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'HARD'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Club, 2: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Impl API Club');
        $club->setSlug('impl-api-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('IAC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('impl-api-' . $uid . '@test.com');
        $user->setFirstName('I');
        $user->setLastName('A');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /** @return list<array{ruleKey: string, intensity: string, minRestDays: ?int, maxConsecutive: ?int, isDefault: bool}> */
    private function members(): array
    {
        /** @var array{member?: list<array{ruleKey: string, intensity: string, minRestDays: ?int, maxConsecutive: ?int, isDefault: bool}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data['member'] ?? [];
    }

    /** @return array{ruleKey: string, intensity: string, minRestDays: ?int, maxConsecutive: ?int, isDefault: bool} */
    private function member(string $ruleKey): array
    {
        foreach ($this->members() as $rule) {
            if ($rule['ruleKey'] === $ruleKey) {
                return $rule;
            }
        }

        self::fail(\sprintf('la règle %s doit figurer dans la collection', $ruleKey));
    }

    /**
     * @param list<array{ruleKey: string}> $rules
     *
     * @return list<string>
     */
    private function sortedKeys(array $rules): array
    {
        $keys = array_map(static fn (array $r): string => $r['ruleKey'], $rules);
        sort($keys);

        return $keys;
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
