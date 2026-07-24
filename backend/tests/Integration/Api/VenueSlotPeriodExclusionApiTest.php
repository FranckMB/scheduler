<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\VenueSlotPeriodExclusion;
use App\Entity\VenueTrainingSlot;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #8 — un créneau de SAISON écarté POUR UNE PÉRIODE. Réglage sparse ancré au plan :
 * l'exclusion existe (le créneau est écarté) ou n'existe pas (il revient) — pas de PUT.
 *
 * Ce que ce test verrouille :
 *  - écarter ne DÉTRUIT jamais le créneau saisonnier (décision fondateur 2026-07-24 :
 *    on doit pouvoir revenir en arrière en supprimant simplement l'exclusion) ;
 *  - un créneau PRÊTÉ ne s'écarte pas — il se supprime (422) ;
 *  - le cloisonnement par période et par club.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenueSlotPeriodExclusionApiTest extends WebTestCase
{
    use TenantGucTrait;

    /** Ancres opaques : ces cas testent le cloisonnement par plan, pas le plan lui-même. */
    private const PLAN = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const OTHER_PLAN = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const VENUE = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const UNKNOWN_SLOT = '99999999-9999-4999-8999-999999999999';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testCreateStampsTenantAndListScopesToPeriod(): void
    {
        $slot = $this->persistSlot(1, '18:00', null);
        $otherSlot = $this->persistSlot(2, '18:00', null);

        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $slot]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($slot, $created['venueTrainingSlotId']);
        self::assertSame(self::PLAN, $created['schedulePlanId']);

        // Le même créneau écarté sur une AUTRE période ne pollue pas celle-ci.
        $this->post(['schedulePlanId' => self::OTHER_PLAN, 'venueTrainingSlotId' => $otherSlot]);
        self::assertResponseStatusCodeSame(201);

        $members = $this->list('?schedulePlanId=' . self::PLAN);
        self::assertCount(1, $members, 'la collection est cloisonnée à la période demandée');
        self::assertSame($slot, $members[0]['venueTrainingSlotId']);

        // Estampillage serveur : ni clubId ni seasonId ne figurent dans le payload.
        $entity = $this->exclusionById($created['id']);
        self::assertSame($this->club->getId(), $entity->getClubId());
        self::assertSame($this->season->getId(), $entity->getSeasonId());
    }

    /**
     * Le cœur du réglage : écarter une semaine ne détruit PAS la structure de la saison.
     * Le créneau saisonnier visé doit être encore là après l'exclusion.
     */
    public function testExcludingASlotNeverDeletesTheSeasonSlot(): void
    {
        $slot = $this->persistSlot(3, '19:00', null);

        $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $slot]);
        self::assertResponseStatusCodeSame(201);

        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($slot), 'le créneau de saison écarté reste en base — le planning principal n’est jamais modifié');
    }

    public function testDuplicateExclusionIsRejectedWithValidationNotServerError(): void
    {
        $slot = $this->persistSlot(4, '20:00', null);

        $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $slot]);
        self::assertResponseStatusCodeSame(201);

        // Un 2e POST sur le même (période, créneau) → 422 propre, pas un 500 d'index unique.
        $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $slot]);
        self::assertResponseStatusCodeSame(422);

        self::assertCount(1, $this->list('?schedulePlanId=' . self::PLAN), 'le doublon n’a rien créé');
    }

    /**
     * Un créneau PRÊTÉ (schedulePlanId non nul) n'appartient qu'à la période : on le
     * supprime, on ne l'écarte pas — sinon une ligne d'exclusion resterait orpheline.
     */
    public function testALentSlotCannotBeExcluded(): void
    {
        $lentSlot = $this->persistSlot(5, '21:00', self::PLAN);

        // 422 de validation, pas un 500 : le refus est métier. (Le message du processor
        // n'est pas propagé dans le corps — voir la note du même test pour le doublon.)
        $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $lentSlot]);
        self::assertResponseStatusCodeSame(422);

        self::assertCount(0, $this->list('?schedulePlanId=' . self::PLAN), 'aucune exclusion n’a été créée');
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($lentSlot), 'le refus ne touche pas le créneau prêté');
    }

    public function testExcludingAnUnknownSlotIsRejected(): void
    {
        $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => self::UNKNOWN_SLOT]);
        self::assertResponseStatusCodeSame(422);

        self::assertCount(0, $this->list('?schedulePlanId=' . self::PLAN));
    }

    /** Supprimer l'exclusion réintègre le créneau : plus de ligne = le créneau revient. */
    public function testDeleteReinstatesTheSlot(): void
    {
        $slot = $this->persistSlot(6, '17:00', null);
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $slot]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->list('?schedulePlanId=' . self::PLAN));

        $this->client->request('DELETE', '/api/venue_slot_period_exclusions/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        self::assertCount(0, $this->list('?schedulePlanId=' . self::PLAN), 'le créneau est réintégré');
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        self::assertNull($this->em->getRepository(VenueSlotPeriodExclusion::class)->find($created['id']));
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($slot), 'le créneau de saison n’a jamais bougé');
    }

    /** NR isolation tenant : un autre club ne voit ni ne peut supprimer l'exclusion d'ici. */
    public function testForeignClubNeitherSeesNorDeletesTheExclusion(): void
    {
        $slot = $this->persistSlot(7, '16:00', null);
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueTrainingSlotId' => $slot]);
        self::assertResponseStatusCodeSame(201);

        $otherHeaders = $this->createOtherClubMember();

        // Même schedulePlanId demandé : si le club n'était pas filtré, la ligne remonterait.
        $this->client->request('GET', '/api/venue_slot_period_exclusions?schedulePlanId=' . self::PLAN, [], [], $otherHeaders);
        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertIsArray($body['member']);
        self::assertCount(0, $body['member'], 'un club ne voit pas les exclusions d’un autre club');

        // 404 et non 403 : sous RLS + TenantFilter la ligne étrangère est INVISIBLE au
        // find() du processor — l'autre club n'apprend même pas qu'elle existe.
        $this->client->request('DELETE', '/api/venue_slot_period_exclusions/' . $created['id'], [], [], $otherHeaders);
        self::assertResponseStatusCodeSame(404, 'un club ne supprime pas — ni ne découvre — l’exclusion d’un autre club');

        self::assertSame($slot, $this->exclusionById($created['id'])->getVenueTrainingSlotId(), 'la ligne d’origine est intacte');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('VSPE ' . $uid)->setSlug('vspe-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('vspe' . $uid . '@test.com')->setFirstName('V')->setLastName('E');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function persistSlot(int $day, string $start, ?string $schedulePlanId): string
    {
        $this->scopeGucToClub($this->club->getId());
        $slot = (new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId(self::VENUE)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)->setCapacity(1)->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot->getId();
    }

    private function exclusionById(string $id): VenueSlotPeriodExclusion
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        $entity = $this->em->getRepository(VenueSlotPeriodExclusion::class)->find($id);
        self::assertInstanceOf(VenueSlotPeriodExclusion::class, $entity);

        return $entity;
    }

    /**
     * Un second club, avec sa saison active et un membre : le tenant est résolu à partir
     * de son JWT + son X-Club-Id.
     *
     * @return array<string, string>
     */
    private function createOtherClubMember(): array
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $club = (new Club)->setName('VSPE other ' . $uid)->setSlug('vspe-other-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $user = (new User)->setEmail('vspe-other' . $uid . '@test.com')->setFirstName('X')->setLastName('Y');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        return [
            'HTTP_X-Club-Id' => $club->getId(),
            'HTTP_X-Season-Id' => $season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/venue_slot_period_exclusions', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /** @return array<int, array<string, mixed>> */
    private function list(string $query): array
    {
        $this->client->request('GET', '/api/venue_slot_period_exclusions' . $query, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $body = $this->decode();
        $members = $body['member'] ?? [];
        self::assertIsArray($members);

        /* @var array<int, array<string, mixed>> $members */
        return $members;
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
