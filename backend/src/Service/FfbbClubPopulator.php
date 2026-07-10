<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\FfbbCommittee;
use App\Entity\FfbbLeague;
use App\Repository\FfbbCommitteeRepository;
use App\Repository\FfbbLeagueRepository;
use App\Storage\LogoStorage;
use App\Storage\LogoUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills a club's institutional data from the FFBB API (lot C), from its FFBB
 * club code alone. Idempotent and best-effort: sub-parts (committee, league,
 * logos) each fail independently without aborting the rest, and a total miss
 * (invalid code / not found) leaves the club untouched. Shared by the async
 * register hook and the (future) superadmin refresh route.
 *
 * `Club.league` is deliberately NOT overwritten — it holds the internal
 * LeagueResolver key (e.g. AURA) that drives the match-window catalog, not the
 * FFBB league code (ARA). The league/committee reference rows are keyed on the
 * FFBB codes and resolved for display from the club's ffbbClubCode prefix +
 * committeeCode. See specs/evolution/import-ffbb-autofill.md + backend/docs/ffbb-api.md.
 */
final class FfbbClubPopulator
{
    public function __construct(
        private readonly FfbbApiClient $api,
        private readonly FfbbLogoFetcher $logoFetcher,
        private readonly LogoStorage $logoStorage,
        private readonly FfbbLeagueRepository $leagues,
        private readonly FfbbCommitteeRepository $committees,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param bool $refresh false (async register hook) = cache-first, leave known
     *                      reference rows untouched; true (explicit import route) =
     *                      re-fetch and overwrite stale committee/league data
     *
     * @return bool true if the club organisme was found and applied
     */
    public function populate(Club $club, bool $refresh = false): bool
    {
        $code = (string) $club->getFfbbClubCode();
        if (!FfbbApiClient::isValidClubCode($code)) {
            return false;
        }

        $hit = $this->firstByCode($this->api->search($code), $code);
        if (null === $hit) {
            $this->logger->info('FFBB populate: club organisme not found', ['code' => $code]);

            return false;
        }

        // Persist the club data first, on its own flush: a later reference-row
        // race (unique-code conflict) must never roll back the club's own fields.
        $this->applyClub($club, $hit);
        $this->em->flush();

        $parent = $this->arr($hit['organisme_id_pere'] ?? null);
        if (null !== $parent) {
            $ligue = $this->arr($parent['organisme_id_pere'] ?? null);
            $this->upsertCommittee($parent, $ligue, $refresh);
            $this->upsertLeague($ligue, $refresh);
        }

        return true;
    }

    /** @param array<string, mixed> $hit */
    private function applyClub(Club $club, array $hit): void
    {
        // Set only when FFBB actually provides a value — never null out a
        // manager-entered (lot B) address/phone/email on a refresh with gaps.
        $this->setIfPresent($this->str($hit['adresse'] ?? null), $club->setAddress(...));
        $this->setIfPresent($this->postalCode($hit), $club->setPostalCode(...));
        $this->setIfPresent($this->city($hit), $club->setCity(...));
        $this->setIfPresent($this->str($hit['telephone'] ?? null), $club->setContactPhone(...));
        $this->setIfPresent($this->str($hit['mail'] ?? null), $club->setContactEmail(...));
        $this->setIfPresent($this->str($hit['urlSiteWeb'] ?? null), $club->setWebsite(...));

        [$lat, $lng] = $this->coordinates($hit);
        if (null !== $lat && null !== $lng) {
            $club->setLatitude($lat);
            $club->setLongitude($lng);
        }

        $parent = $this->arr($hit['organisme_id_pere'] ?? null);
        $this->setIfPresent(null === $parent ? null : $this->str($parent['code'] ?? null), $club->setCommitteeCode(...));

        // Club logo: only set it when the club has none (never clobber an upload).
        if (null === $club->getLogoUrl()) {
            $bytes = $this->logoBytes($hit);
            if (null !== $bytes) {
                $this->logoStorage->store($club->getId(), $bytes);
                $club->setLogoUrl(LogoUrl::build($club->getId(), $bytes));
            }
        }
    }

    /** @param callable(?string): mixed $setter */
    private function setIfPresent(?string $value, callable $setter): void
    {
        if (null !== $value) {
            $setter($value);
        }
    }

    /**
     * @param array<string, mixed>      $parent nested committee organisme
     * @param array<string, mixed>|null $ligue  nested league organisme (for leagueCode)
     */
    private function upsertCommittee(array $parent, ?array $ligue, bool $refresh): void
    {
        $code = $this->str($parent['code'] ?? null);
        if (null === $code || (!$refresh && null !== $this->committees->findByCode($code))) {
            return; // cache-first: already known → no fetch/write
        }

        try {
            $full = $this->firstByCode($this->api->search((string) $this->str($parent['nom'] ?? '')), $code) ?? $parent;
            $entity = (new FfbbCommittee)
                ->setCode($code)
                ->setLeagueCode(null === $ligue ? null : $this->str($ligue['code'] ?? null))
                ->setName((string) ($this->str($full['nom'] ?? $parent['nom'] ?? null) ?? $code));
            $this->applyOrganismeContact($full, $entity, 'ffbb-committee-' . $code, 'committee', $code);
            $this->committees->upsert($entity, $refresh);
        } catch (Throwable $e) {
            $this->logger->warning('FFBB populate: committee upsert failed', ['code' => $code, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed>|null $ligue nested league organisme */
    private function upsertLeague(?array $ligue, bool $refresh): void
    {
        if (null === $ligue) {
            return;
        }
        $code = $this->str($ligue['code'] ?? null);
        if (null === $code || (!$refresh && null !== $this->leagues->findByCode($code))) {
            return;
        }

        try {
            $full = $this->firstByCode($this->api->search((string) $this->str($ligue['nom'] ?? '')), $code) ?? $ligue;
            $entity = (new FfbbLeague)
                ->setCode($code)
                ->setName((string) ($this->str($full['nom'] ?? $ligue['nom'] ?? null) ?? $code));
            $this->applyOrganismeContact($full, $entity, 'ffbb-league-' . $code, 'league', $code);
            $this->leagues->upsert($entity, $refresh);
        } catch (Throwable $e) {
            $this->logger->warning('FFBB populate: league upsert failed', ['code' => $code, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Shared mapping for the two reference entities (same setters).
     *
     * @param array<string, mixed> $hit
     */
    private function applyOrganismeContact(array $hit, FfbbLeague|FfbbCommittee $entity, string $storageKey, string $scope, string $code): void
    {
        $entity->setAddress($this->str($hit['adresse'] ?? null));
        $entity->setPostalCode($this->postalCode($hit));
        $entity->setCity($this->city($hit));
        $entity->setPhone($this->str($hit['telephone'] ?? null));
        $entity->setEmail($this->str($hit['mail'] ?? null));

        $bytes = $this->logoBytes($hit);
        if (null !== $bytes) {
            $this->logoStorage->store($storageKey, $bytes);
            $entity->setLogoUrl(\sprintf('/api/ffbb-logos/%s/%s?v=%s', $scope, $code, substr(md5($bytes), 0, 8)));
        }
    }

    /**
     * @param list<array<string, mixed>> $hits
     *
     * @return array<string, mixed>|null the hit whose `code` matches exactly
     *                                   (case-insensitive), or null — NEVER an
     *                                   arbitrary neighbour (Meilisearch is
     *                                   typo-tolerant; hits[0] could be a
     *                                   different organisation entirely)
     */
    private function firstByCode(array $hits, string $code): ?array
    {
        foreach ($hits as $hit) {
            if (0 === strcasecmp((string) ($hit['code'] ?? ''), $code)) {
                return $hit;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $hit */
    private function logoBytes(array $hit): ?string
    {
        $logo = $this->arr($hit['logo'] ?? null);
        $uuid = null === $logo ? null : $this->str($logo['id'] ?? null);

        return null === $uuid ? null : $this->logoFetcher->download($uuid);
    }

    /** @param array<string, mixed> $hit */
    private function postalCode(array $hit): ?string
    {
        $carto = $this->arr($hit['cartographie'] ?? null);
        $commune = $this->arr($hit['commune'] ?? null);

        return $this->str($carto['codePostal'] ?? null) ?? $this->str($commune['codePostal'] ?? null);
    }

    /** @param array<string, mixed> $hit */
    private function city(array $hit): ?string
    {
        $commune = $this->arr($hit['commune'] ?? null);
        $carto = $this->arr($hit['cartographie'] ?? null);

        return $this->str($commune['libelle'] ?? null) ?? $this->str($carto['ville'] ?? null);
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array{0: ?float, 1: ?float} [latitude, longitude]
     */
    private function coordinates(array $hit): array
    {
        $geo = $this->arr($hit['_geo'] ?? null);
        if (null !== $geo && isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng'])) {
            return [(float) $geo['lat'], (float) $geo['lng']];
        }

        return [null, null];
    }

    private function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arr(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }
}
