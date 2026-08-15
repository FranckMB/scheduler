<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\TeamTagAxis;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260815120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR — la migration Volet A « tags » : `SENIOR` (= « adulte », `ageMin >= 19`) devient `ADULTE`,
 * et les contraintes qui le ciblaient suivent. Les assignations gardent le `tag_id` INCHANGÉ
 * (rename), donc aucune n'est perdue et aucun planning n'a à être périmé.
 *
 * On exécute le VRAI SQL de la migration (via `getSql()`, jamais une copie), sous GUC de club :
 * la RLS borne alors les UPDATE/DELETE globaux au seul club de test.
 */
#[Group('integration')]
final class MigrationTeamTagSeniorRenameTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testSeniorTagIsRenamedAndConstraintsRepointToAdulte(): void
    {
        [$club, $season] = $this->makeClubAndSeason();

        $senior = $this->makeTag($club->getId(), 'SENIOR', true);
        $teamId = Uuid::v4()->toRfc4122();
        $this->assign($club->getId(), $season->getId(), $teamId, $senior->getId());

        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setName('Groupe Adulte (+ de 18) · pas avant 19:00');
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::PREFERRED);
        $constraint->setConfig(['targetTag' => 'SENIOR', 'minStartTime' => '19:00']);
        $constraint->setSortOrder(0);
        $this->em->persist($constraint);
        $this->em->flush();

        $seniorId = $senior->getId();
        $constraintId = $constraint->getId();

        $this->runMigration();
        $this->em->clear();

        // Le tag garde son id (rename, pas recréation) mais s'appelle désormais ADULTE.
        $renamed = $this->em->getRepository(TeamTag::class)->find($seniorId);
        self::assertInstanceOf(TeamTag::class, $renamed);
        self::assertSame('ADULTE', $renamed->getName(), 'le tag SENIOR est renommé ADULTE, même ligne');

        // L'assignation référence le MÊME tag_id → sémantique préservée.
        $assignments = $this->em->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $seniorId]);
        self::assertCount(1, $assignments, 'l\'assignation suit le tag_id inchangé, aucune perte');

        // La contrainte pointe désormais ADULTE.
        $reloaded = $this->em->getRepository(Constraint::class)->find($constraintId);
        self::assertInstanceOf(Constraint::class, $reloaded);
        self::assertSame('ADULTE', $reloaded->getConfig()['targetTag'], 'la contrainte cible ADULTE après migration');
        self::assertSame('19:00', $reloaded->getConfig()['minStartTime'], 'le reste du config est intact');
    }

    public function testHomonymClubMergesSeniorIntoExistingAdulteWithoutLosingAssignments(): void
    {
        [$club, $season] = $this->makeClubAndSeason();

        // Le cas de conflit : le club a DÉJÀ un tag « ADULTE » (personnalisé) — le rename direct
        // heurterait `uniq_team_tag_club_name`. On fusionne alors le SENIOR dans l'ADULTE.
        $senior = $this->makeTag($club->getId(), 'SENIOR', true);
        $adulte = $this->makeTag($club->getId(), 'ADULTE', false);

        // team1 : SENIOR seul → doit basculer sur ADULTE.
        $team1 = Uuid::v4()->toRfc4122();
        $this->assign($club->getId(), $season->getId(), $team1, $senior->getId());
        // team2 : DÉJÀ ADULTE + SENIOR → le SENIOR redondant part sans doublonner l'ADULTE.
        $team2 = Uuid::v4()->toRfc4122();
        $this->assign($club->getId(), $season->getId(), $team2, $senior->getId());
        $this->assign($club->getId(), $season->getId(), $team2, $adulte->getId());
        $this->em->flush();

        $seniorId = $senior->getId();
        $adulteId = $adulte->getId();

        $this->runMigration();
        $this->em->clear();

        // Le tag SENIOR devenu orphelin est supprimé ; l'ADULTE en place subsiste.
        self::assertNull($this->em->getRepository(TeamTag::class)->find($seniorId), 'le tag SENIOR en doublon est supprimé');
        self::assertInstanceOf(TeamTag::class, $this->em->getRepository(TeamTag::class)->find($adulteId));
        self::assertSame([], $this->em->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $seniorId]), 'plus aucune assignation ne pointe le SENIOR supprimé');

        // team1 basculé, team2 non-doublonné : chaque équipe porte EXACTEMENT une assignation ADULTE.
        $adulteAssignments = $this->em->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $adulteId]);
        $teamIds = array_map(static fn (TeamTagAssignment $a): string => $a->getTeamId(), $adulteAssignments);
        sort($teamIds);
        $expected = [$team1, $team2];
        sort($expected);
        self::assertSame($expected, $teamIds, 'team1 basculé sur ADULTE, team2 gardé une seule fois (aucune perte, aucun doublon)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function makeClubAndSeason(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Rename Migration Club');
        $club->setSlug('rename-mig-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

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

    private function makeTag(string $clubId, string $name, bool $isSystem): TeamTag
    {
        $tag = new TeamTag;
        $tag->setClubId($clubId);
        $tag->setName($name);
        $tag->setColor('#4ECDC4');
        $tag->setIsSystem($isSystem);
        $tag->setAxis(TeamTagAxis::AGE);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function assign(string $clubId, string $seasonId, string $teamId, string $tagId): void
    {
        $assignment = new TeamTagAssignment;
        $assignment->setClubId($clubId);
        $assignment->setSeasonId($seasonId);
        $assignment->setTeamId($teamId);
        $assignment->setTagId($tagId);
        $this->em->persist($assignment);
    }

    private function runMigration(): void
    {
        $migration = new Version20260815120000($this->em->getConnection(), new NullLogger);
        $migration->up(new Schema);
        $connection = $this->em->getConnection();
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
