<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueSlotPeriodExclusion;
use App\Entity\VenueTrainingSlot;
use App\Enum\VenuePeriodMode;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #8 — mode d'un gymnase POUR UNE PÉRIODE (réglage sparse ancré au plan) : l'API
 * estampille le tenant/la saison côté serveur, cloisonne la collection par période,
 * et la bascule en DÉSACTIVÉ purge — atomiquement — tout ce qui n'a plus de sens
 * pour ce gymnase sur cette période.
 *
 * Invariant fondateur n°1 verrouillé ici : le PLANNING PRINCIPAL n'est jamais
 * modifié — les créneaux de SAISON du gymnase survivent à la désactivation.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenuePeriodOverrideApiTest extends WebTestCase
{
    use TenantGucTrait;

    /** Ancres opaques : ces cas testent le cloisonnement par plan, pas le plan lui-même. */
    private const PLAN = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const OTHER_PLAN = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const VENUE = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const OTHER_VENUE = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const TEAM = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testCreateStampsTenantAndListScopesToPeriod(): void
    {
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('BLANK', $created['mode']);
        self::assertSame(self::PLAN, $created['schedulePlanId']);
        self::assertSame(self::VENUE, $created['venueId']);

        // Le même gymnase réglé sur une AUTRE période ne doit pas polluer celle-ci.
        $this->post(['schedulePlanId' => self::OTHER_PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);

        $members = $this->list('?schedulePlanId=' . self::PLAN);
        self::assertCount(1, $members, 'la collection est cloisonnée à la période demandée');
        self::assertSame('BLANK', $members[0]['mode']);

        // Le filtre venueId affine encore.
        self::assertCount(0, $this->list('?schedulePlanId=' . self::PLAN . '&venueId=' . self::OTHER_VENUE));
        self::assertCount(1, $this->list('?schedulePlanId=' . self::PLAN . '&venueId=' . self::VENUE));

        // La ligne est estampillée club + saison côté serveur (aucun des deux n'est dans le payload).
        $entity = $this->overrideById($created['id']);
        self::assertSame($this->club->getId(), $entity->getClubId());
        self::assertSame($this->season->getId(), $entity->getSeasonId());
    }

    public function testDuplicateOverrideIsRejectedWithValidationNotServerError(): void
    {
        $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        // Un 2e POST sur le même (période, gymnase) → 422 propre, pas un 500 d'index unique.
        $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(422);

        self::assertCount(1, $this->list('?schedulePlanId=' . self::PLAN), 'le doublon n’a rien créé');
    }

    public function testUpdateSwitchesTheMode(): void
    {
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame('DISABLED', $body['mode']);

        self::assertSame(VenuePeriodMode::DISABLED, $this->overrideById($created['id'])->getMode(), 'le mode est bien persisté');
    }

    /**
     * INHERIT n'est pas stocké : revenir au défaut, c'est SUPPRIMER la ligne. Après le
     * DELETE le gymnase n'a plus aucun réglage pour la période.
     */
    public function testDeleteReturnsTheVenueToTheInheritDefault(): void
    {
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->list('?schedulePlanId=' . self::PLAN));

        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        self::assertCount(0, $this->list('?schedulePlanId=' . self::PLAN), 'plus de ligne = INHERIT, le défaut');
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        self::assertNull($this->em->getRepository(VenuePeriodOverride::class)->find($created['id']));
    }

    /** NR isolation tenant : un autre club ne voit ni ne peut écrire le réglage d'ici. */
    public function testForeignClubNeitherSeesNorWritesTheOverride(): void
    {
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        [$otherHeaders] = $this->createOtherClubMember();

        // Même schedulePlanId demandé : si le club n'était pas filtré, la ligne remonterait.
        $this->client->request('GET', '/api/venue_period_overrides?schedulePlanId=' . self::PLAN, [], [], $otherHeaders);
        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertIsArray($body['member']);
        self::assertCount(0, $body['member'], 'un club ne voit pas les réglages de gymnase d’un autre club');

        // 404 et non 403 : sous RLS + TenantFilter la ligne étrangère est INVISIBLE au
        // find() du processor — l'autre club n'apprend même pas qu'elle existe.
        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $otherHeaders, json_encode(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404, 'un club ne modifie pas — ni ne découvre — le réglage d’un autre club');

        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $otherHeaders);
        self::assertResponseStatusCodeSame(404, 'un club ne supprime pas — ni ne découvre — le réglage d’un autre club');

        // La ligne d'origine est intacte.
        self::assertSame(VenuePeriodMode::BLANK, $this->overrideById($created['id'])->getMode());
    }

    /**
     * Bascule en DÉSACTIVÉ par POST : dans la MÊME transaction, les créneaux PRÊTÉS du
     * plan pour ce gymnase, ses réservations de période et les exclusions visant ses
     * créneaux de saison disparaissent. Ce qui appartient au planning principal — les
     * créneaux de SAISON — survit, et rien d'un AUTRE gymnase n'est touché.
     */
    public function testDisablingAVenuePurgesItsPeriodRowsAndSparesTheSeasonStructure(): void
    {
        $seed = $this->seedCascadeFixture();

        $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);

        $this->assertCascadePurged($seed);
    }

    /**
     * Même purge par ÉDITION (grille vierge → désactivé) : le chemin PUT ne doit pas
     * laisser passer les orphelins que le chemin POST nettoie.
     */
    public function testEditingAVenueToDisabledPurgesTheSameWay(): void
    {
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        $seed = $this->seedCascadeFixture();

        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['schedulePlanId' => self::PLAN, 'venueId' => self::VENUE, 'mode' => 'DISABLED'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->assertCascadePurged($seed);
    }

    /**
     * NR — la purge suit la ligne ÉDITÉE, jamais le corps de la requête. schedulePlanId et
     * venueId identifient l'override et ne sont pas remappés à l'édition : un PUT qui les
     * change ne déplace pas la ligne, il ne doit donc surtout pas emporter les créneaux et
     * réservations du couple (plan, gymnase) que le corps désigne. Défaut réel trouvé en
     * revue de cette PR : la purge lisait l'input, elle vidait une AUTRE période.
     */
    public function testDisablingNeverPurgesThePeriodNamedInTheBody(): void
    {
        // La ligne éditée porte sur OTHER_VENUE ; le corps, lui, désignera VENUE.
        $created = $this->post(['schedulePlanId' => self::PLAN, 'venueId' => self::OTHER_VENUE, 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);
        $seed = $this->seedCascadeFixture();

        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode([
            'schedulePlanId' => self::PLAN,
            'venueId' => self::VENUE, // ← le corps vise un AUTRE gymnase que la ligne éditée
            'mode' => 'DISABLED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        // Le gymnase nommé dans le CORPS n'a rien perdu : sa purge n'avait pas lieu d'être.
        self::assertNotNull(
            $this->em->getRepository(VenueTrainingSlot::class)->find($seed['lentSlot']),
            'un PUT ne purge jamais le couple (plan, gymnase) que son corps désigne — seulement la ligne éditée',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('VPO ' . $uid)->setSlug('vpo-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('vpo' . $uid . '@test.com')->setFirstName('V')->setLastName('O');
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

    /**
     * Les lignes que la désactivation doit emporter — et leurs témoins, qui doivent
     * survivre : le créneau de SAISON du gymnase (planning principal) et le créneau
     * prêté d'un AUTRE gymnase de la même période (la purge est ciblée).
     *
     * @return array{lentSlot: string, reservation: string, exclusion: string, seasonSlot: string, otherVenueLentSlot: string, otherVenueExclusion: string}
     */
    private function seedCascadeFixture(): array
    {
        $this->scopeGucToClub($this->club->getId());

        $seasonSlot = $this->persistSlot(self::VENUE, 1, '18:00', null);
        $otherSeasonSlot = $this->persistSlot(self::OTHER_VENUE, 2, '18:00', null);
        $lentSlot = $this->persistSlot(self::VENUE, 3, '19:00', self::PLAN);
        $otherVenueLentSlot = $this->persistSlot(self::OTHER_VENUE, 4, '19:00', self::PLAN);

        $reservation = (new Reservation)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(self::PLAN)->setTeamId(self::TEAM)->setVenueId(self::VENUE)
            ->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90);
        $this->em->persist($reservation);

        $exclusion = (new VenueSlotPeriodExclusion)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(self::PLAN)->setVenueTrainingSlotId($seasonSlot);
        $this->em->persist($exclusion);

        // Témoin : une exclusion de la même période visant le créneau de saison d'un
        // AUTRE gymnase — désactiver celui-ci ne doit pas l'emporter.
        $otherVenueExclusion = (new VenueSlotPeriodExclusion)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(self::PLAN)->setVenueTrainingSlotId($otherSeasonSlot);
        $this->em->persist($otherVenueExclusion);
        $this->em->flush();

        $seed = [
            'lentSlot' => $lentSlot,
            'reservation' => $reservation->getId(),
            'exclusion' => $exclusion->getId(),
            'seasonSlot' => $seasonSlot,
            'otherVenueLentSlot' => $otherVenueLentSlot,
            'otherVenueExclusion' => $otherVenueExclusion->getId(),
        ];

        // AVANT : les trois lignes visées existent bel et bien (assertion non vacante —
        // sans elle, un « c'est parti » ne prouverait rien).
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($seed['lentSlot']), 'le créneau prêté est en place avant la bascule');
        self::assertNotNull($this->em->getRepository(Reservation::class)->find($seed['reservation']), 'la réservation est en place avant la bascule');
        self::assertNotNull($this->em->getRepository(VenueSlotPeriodExclusion::class)->find($seed['exclusion']), 'l’exclusion est en place avant la bascule');

        return $seed;
    }

    /** @param array{lentSlot: string, reservation: string, exclusion: string, seasonSlot: string, otherVenueLentSlot: string, otherVenueExclusion: string} $seed */
    private function assertCascadePurged(array $seed): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();

        self::assertNull($this->em->getRepository(VenueTrainingSlot::class)->find($seed['lentSlot']), 'le créneau PRÊTÉ n’a plus de sens sur un gymnase désactivé');
        self::assertNull($this->em->getRepository(Reservation::class)->find($seed['reservation']), 'la réservation de période part avec le gymnase désactivé');
        self::assertNull($this->em->getRepository(VenueSlotPeriodExclusion::class)->find($seed['exclusion']), 'l’exclusion visant un créneau de ce gymnase n’a plus d’objet');

        // Invariant fondateur n°1 : le planning principal n'est JAMAIS modifié.
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($seed['seasonSlot']), 'le créneau de SAISON du gymnase survit — le planning principal n’est jamais modifié');
        // La purge est ciblée sur CE gymnase.
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($seed['otherVenueLentSlot']), 'le créneau prêté d’un autre gymnase de la période survit');
        self::assertNotNull($this->em->getRepository(VenueSlotPeriodExclusion::class)->find($seed['otherVenueExclusion']), 'l’exclusion visant un autre gymnase survit');
    }

    private function persistSlot(string $venueId, int $day, string $start, ?string $schedulePlanId): string
    {
        $slot = (new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)->setCapacity(1)->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot->getId();
    }

    private function overrideById(string $id): VenuePeriodOverride
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        $entity = $this->em->getRepository(VenuePeriodOverride::class)->find($id);
        self::assertInstanceOf(VenuePeriodOverride::class, $entity);

        return $entity;
    }

    /**
     * Un second club, avec sa saison active et un membre : le tenant est résolu à partir
     * de son JWT + son X-Club-Id.
     *
     * @return array{0: array<string, string>, 1: Club}
     */
    private function createOtherClubMember(): array
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $club = (new Club)->setName('VPO other ' . $uid)->setSlug('vpo-other-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $user = (new User)->setEmail('vpo-other' . $uid . '@test.com')->setFirstName('X')->setLastName('Y');
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

        return [[
            'HTTP_X-Club-Id' => $club->getId(),
            'HTTP_X-Season-Id' => $season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/ld+json',
        ], $club];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/venue_period_overrides', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /** @return array<int, array<string, mixed>> */
    private function list(string $query): array
    {
        $this->client->request('GET', '/api/venue_period_overrides' . $query, [], [], $this->headers());
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
