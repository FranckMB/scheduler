<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Repository\ConstraintRepository;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Constraint semantics (NR): a dated constraint (calendarEntryId set) belongs to
 * a CalendarEntry period and MUST be excluded from base-plan generation. The
 * generation snapshot loads constraints via findPermanentByClubSeason, so a
 * dated constraint must never reach the engine payload.
 */
#[Group('phase1')]
#[Group('integration')]
final class DatedConstraintExclusionTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testDatedConstraintExcludedFromPermanentQuery(): void
    {
        [$club, $season] = $this->seed();

        $permanent = $this->makeConstraint($club, $season, 'Permanente', null);
        $dated = $this->makeConstraint($club, $season, 'Datée', '11111111-1111-4111-8111-111111111111');
        $this->em->flush();

        /** @var ConstraintRepository $repo */
        $repo = $this->em->getRepository(Constraint::class);

        $permanentOnly = $repo->findPermanentByClubSeason($club->getId(), $season->getId());
        $ids = array_map(static fn (Constraint $c): string => $c->getId(), $permanentOnly);

        self::assertContains($permanent->getId(), $ids, 'permanent constraint must feed generation');
        self::assertNotContains($dated->getId(), $ids, 'dated constraint must be excluded from generation');

        // Sanity: the unfiltered query still sees both (the exclusion is the filter, not a data loss).
        $all = $repo->findByClubSeason($club->getId(), $season->getId());
        self::assertCount(2, $all);
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeConstraint(Club $club, Season $season, string $name, ?string $calendarEntryId): Constraint
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName($name);
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setCalendarEntryId($calendarEntryId);
        $this->em->persist($c);

        return $c;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('DCE Club');
        $club->setSlug('dce-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('DCE' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('dce-' . $uid . '@test.com');
        $user->setFirstName('D');
        $user->setLastName('CE');
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
        $season->setStatus('active');
        $this->em->persist($season);

        $this->em->flush();

        return [$club, $season];
    }
}
