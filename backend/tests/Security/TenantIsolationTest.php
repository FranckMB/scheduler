<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class TenantIsolationTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testUserCannotAccessOtherClubData(): void
    {
        [$clubA, $clubB, $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubB->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCanAccessOwnClubData(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubA->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testInactiveMembershipBlocksAccess(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();

        $membership = $this->em->getRepository(ClubUser::class)->findOneBy([
            'userId' => $userA->getId(),
            'clubId' => $clubA->getId(),
        ]);
        $membership->setIsActive(false);
        $this->em->flush();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubA->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le club porté par le header doit être un UUID CANONIQUE — revue sécurité
     * FRT-04, exploit mesuré sur la stack de dev.
     *
     * PostgreSQL accepte `{710a290720ce40ffa67fc2674ca0dbe7}` comme le même uuid
     * que `710a2907-20ce-...` : un membre pouvait donc envoyer SON PROPRE club
     * sous cette forme dégradée et passer le contrôle d'adhésion (la comparaison
     * est faite par la base, qui normalise). La chaîne, elle, continuait son
     * chemin telle quelle dans `_club_id` — et tout aval qui ne normalise pas
     * héritait d'une valeur choisie par le client. Sur `/api/mercure/auth` elle
     * produisait le sélecteur `club:{710a29...}:schedule:{id}`, soit DEUX
     * variables URI-template : le hub y délivrait les événements de génération
     * de N'IMPORTE QUEL club (reçus en vivant avant correctif).
     *
     * Le club est la frontière tenant : sa FORME se valide au listener, comme
     * celle de la saison, et un écart se refuse — jamais une normalisation
     * silencieuse, qui rendrait le club sous deux graphies « le même » ici et
     * « deux valeurs » ailleurs.
     */
    public function testANonCanonicalClubHeaderIsRejectedEvenForOwnClub(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();
        $mangled = '{' . str_replace('-', '', $clubA->getId()) . '}';

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $mangled,
        ]);

        self::assertResponseStatusCodeSame(403, 'un club sous forme non canonique doit être refusé, même si c’est le sien');
    }

    public function testNoClubHeaderReturnsData(): void
    {
        [, , $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams');
        self::assertResponseIsSuccessful();
    }

    /**
     * Un réglage de règle implicite (bien-être) posé par le club A est INVISIBLE au club B : la
     * collection RÉSOLUE de B reste au défaut HARD. RLS + filtre tenant bornent la ligne au club.
     */
    public function testImplicitRuleSettingOfClubAIsInvisibleToClubB(): void
    {
        [$clubA, $clubB, $userA] = $this->createTwoClubs();

        // Un second gestionnaire, membre du club B seulement.
        $this->scopeGucToClub($clubB->getId());
        $userB = new User;
        $userB->setEmail('b' . uniqid('', true) . '@test.com');
        $userB->setFirstName('B');
        $userB->setLastName('User');
        $userB->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($userB, 'pass'));
        $this->em->persist($userB);
        $this->em->flush();
        $cuB = new ClubUser;
        $cuB->setClubId($clubB->getId());
        $cuB->setUserId($userB->getId());
        $cuB->setRole('admin');
        $cuB->setIsActive(true);
        $this->em->persist($cuB);
        // Le club B a sa propre saison courante (sinon sa collection résolue serait vide,
        // faute de saison à scoper).
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $seasonB = new Season;
        $seasonB->setClubId($clubB->getId());
        $seasonB->setName($year . '-' . ($year + 1));
        $seasonB->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $seasonB->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $seasonB->setStatus(SeasonStatus::ACTIVE);
        $seasonB->setTransitionData([]);
        $this->em->persist($seasonB);
        $this->em->flush();

        // Club A assouplit coachRestDay (écrit directement sous le contexte tenant du club A).
        $this->scopeGucToClub($clubA->getId());
        $seasonA = new Season;
        $seasonA->setClubId($clubA->getId());
        $seasonA->setName('2025-2026');
        $seasonA->setStartDate(new DateTimeImmutable('2025-08-01'));
        $seasonA->setEndDate(new DateTimeImmutable('2026-07-15'));
        $seasonA->setStatus(SeasonStatus::ACTIVE);
        $seasonA->setTransitionData([]);
        $this->em->persist($seasonA);
        $this->em->flush();
        $setting = new ImplicitRuleSetting;
        $setting->setClubId($clubA->getId());
        $setting->setSeasonId($seasonA->getId());
        $setting->setRuleKey(ImplicitRuleKey::COACH_REST_DAY);
        $setting->setIntensity(ImplicitRuleIntensity::PREFERRED);
        $this->em->persist($setting);
        $this->em->flush();

        // Le club B ne voit QUE le défaut — la ligne du club A lui est invisible.
        $this->client->loginUser($userB);
        $this->client->request('GET', '/api/implicit_rule_settings', [], [], [
            'HTTP_X-Club-Id' => $clubB->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
        /** @var array{member?: list<array{ruleKey: string, intensity: string}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $coachRest = array_values(array_filter($data['member'] ?? [], static fn (array $r): bool => 'coachRestDay' === $r['ruleKey']));
        self::assertNotEmpty($coachRest, 'la collection résolue du club B porte coachRestDay');
        self::assertSame('HARD', $coachRest[0]['intensity'], 'le club B reste au défaut — le réglage du club A ne fuit pas');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Club, 2: User} */
    private function createTwoClubs(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $clubA = new Club;
        $clubA->setName('Club A');
        $clubA->setSlug('club-a-' . $uid);
        $clubA->setTimezone('Europe/Paris');
        $clubA->setLocale('fr');
        $clubA->setOnboardingCompleted(true);
        $clubA->setFfbbClubCode('AAA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($clubA);

        $clubB = new Club;
        $clubB->setName('Club B');
        $clubB->setSlug('club-b-' . $uid);
        $clubB->setTimezone('Europe/Paris');
        $clubB->setLocale('fr');
        $clubB->setOnboardingCompleted(true);
        $clubB->setFfbbClubCode('BBB' . strtoupper(substr(md5($uid . 'b'), 0, 10)));
        $this->em->persist($clubB);

        $userA = new User;
        $userA->setEmail('a' . $uid . '@test.com');
        $userA->setFirstName('A');
        $userA->setLastName('User');
        $userA->setPasswordHash($hasher->hashPassword($userA, 'pass'));
        $this->em->persist($userA);

        $this->em->flush();

        $this->scopeGucToClub($clubA->getId());

        $cu = new ClubUser;
        $cu->setClubId($clubA->getId());
        $cu->setUserId($userA->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();

        return [$clubA, $clubB, $userA];
    }
}
