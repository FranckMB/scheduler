<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SeasonStatus;
use App\Service\ImplicitRuleResolver;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + sémantique de contrainte (§7.1).
 *
 * Les 4 règles implicites « bien-être » sont réglables par club/saison (intensité HARD/PREFERRED
 * + seuils, contrat 2.7). Ce que le gestionnaire STOCKE doit être EXACTEMENT le bloc
 * `implicitRules` que le payload émet au solveur — DÉFAUTS RÉSOLUS compris (une règle sans ligne
 * = HARD, seuil historique). Le résolveur (`ImplicitRuleResolver`) est la maison unique dont
 * DÉRIVENT et la collection GET et le payload, ce qui rend « stocké == émis » vrai par
 * construction — ce test le PROUVE, dans les deux sens, sur le chemin base ET le chemin overlay
 * de période (season-scopé, ADR-0002 : pas de calendarEntryId).
 *
 * Falsifié dans les deux sens : un réglage stocké DOIT apparaître dans le payload (un builder qui
 * émettrait toujours les défauts échouerait), et une règle NON stockée DOIT valoir le défaut (un
 * builder qui inventerait une valeur échouerait).
 */
#[Group('phase1')]
#[Group('integration')]
final class ImplicitRulePayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ImplicitRuleResolver $resolver;

    /**
     * Chemin base : le bloc émis == le bloc résolu, réglages stockés compris, défauts pour le reste.
     */
    public function testClubSeasonPayloadEmitsTheResolvedBlockStoredOverDefaults(): void
    {
        [$club, $season] = $this->seed();

        // Deux règles DÉVIÉES du défaut : un cran (salarie → PREFERRED) et un cran + seuil
        // (coachRestDay → PREFERRED, minRestDays 3). Les deux autres restent au défaut (absentes).
        $this->store($club, $season, ImplicitRuleKey::COACH_REST_DAY, ImplicitRuleIntensity::PREFERRED, ['minRestDays' => 3]);
        $this->store($club, $season, ImplicitRuleKey::SALARIE_DISTRIBUTION, ImplicitRuleIntensity::PREFERRED, null);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        // Parité stricte : le payload émet EXACTEMENT ce que le résolveur résout — maison unique.
        self::assertSame(
            $this->resolver->resolve($club->getId(), $season->getId()),
            $payload['implicitRules'],
            'le bloc implicitRules émis doit être EXACTEMENT le bloc résolu',
        );

        // Sens 1 — le stocké est REFLÉTÉ (un builder qui émettrait toujours les défauts échoue ici).
        self::assertSame(['intensity' => 'PREFERRED', 'minRestDays' => 3], $payload['implicitRules']['coachRestDay']);
        self::assertSame(['intensity' => 'PREFERRED'], $payload['implicitRules']['salarieDistribution']);

        // Sens 2 — le NON stocké vaut le DÉFAUT (un builder qui inventerait une valeur échoue ici).
        self::assertSame(['intensity' => 'HARD', 'maxConsecutive' => 3], $payload['implicitRules']['maxConsecutiveSessions']);
        self::assertSame(['intensity' => 'HARD'], $payload['implicitRules']['ageAscending']);
    }

    /**
     * Chemin overlay de période : MÊME bloc season-scopé (ADR-0002) — un réglage vaut aussi pour
     * les périodes, sans calendarEntryId propre.
     */
    public function testPeriodOverlayPayloadEmitsTheSameSeasonScopedBlock(): void
    {
        [$club, $season] = $this->seed();

        $this->store($club, $season, ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS, ImplicitRuleIntensity::PREFERRED, ['maxConsecutive' => 5]);
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertSame(
            $this->resolver->resolve($club->getId(), $season->getId()),
            $payload['implicitRules'],
            'l\'overlay de période émet le MÊME bloc season-scopé que la base',
        );
        self::assertSame(['intensity' => 'PREFERRED', 'maxConsecutive' => 5], $payload['implicitRules']['maxConsecutiveSessions']);
    }

    /**
     * Absence totale de réglage = payload aux DÉFAUTS (tout HARD, seuils historiques) — le payload
     * d'absence de PR 1, byte-identique.
     */
    public function testNoStoredSettingsEmitsAllDefaults(): void
    {
        [$club, $season] = $this->seed();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(ImplicitRuleResolver::defaults(), $payload['implicitRules']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $this->resolver = self::getContainer()->get(ImplicitRuleResolver::class);
    }

    /** @param array<string, mixed>|null $params */
    private function store(Club $club, Season $season, ImplicitRuleKey $ruleKey, ImplicitRuleIntensity $intensity, ?array $params): void
    {
        $setting = new ImplicitRuleSetting;
        $setting->setClubId($club->getId());
        $setting->setSeasonId($season->getId());
        $setting->setRuleKey($ruleKey);
        $setting->setIntensity($intensity);
        $setting->setParams($params);
        $this->em->persist($setting);
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Implicit Parity Club');
        $club->setSlug('impl-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('IPC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('impl-parity-' . $uid . '@test.com');
        $user->setFirstName('I');
        $user->setLastName('P');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
