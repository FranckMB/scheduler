<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\TeamTagResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NR — `app:team-tags:resync` pose les tags système sur un club EXISTANT.
 *
 * Le `TeamTagSyncListener` ne dérive les tags qu'à l'écriture d'une équipe/catégorie : quand le
 * catalogue gagne de nouveaux membres (SENIOR/COMPETITION, Volet A), un club dont rien n'a bougé
 * reste sans eux. On simule cet état — tag + assignation SUPPRIMÉS — puis on prouve que la
 * commande les rétablit sans toucher à l'équipe (elle rejoue `syncTeamTags`, foyer de la règle).
 */
#[Group('integration')]
final class ResyncTeamTagsCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TeamTagResolver $resolver;

    public function testResyncPosesTheNewTagsOnAnExistingClub(): void
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Resync Club');
        $club->setSlug('resync-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-resync-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        // Catégorie « Senior » (22-99) : ageMin >= 22 → ADULTE + SENIOR ; niveau REGIONAL → COMPETITION.
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('Senior');
        $category->setAgeMin(22);
        $category->setAgeMax(99);
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName('SM1');
        $team->setLevel(TeamLevel::REGIONAL);
        $team->setSessionsPerWeek(1);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $teamId = $team->getId();

        // Simuler un club d'AVANT le lot : le tag ET l'assignation SENIOR/COMPETITION n'existent
        // pas encore. On les efface (le listener vient de les poser à la création de l'équipe).
        $this->scopeGucToClub($club->getId());
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'DELETE FROM team_tag_assignment WHERE club_id = :club AND tag_id IN (SELECT id FROM team_tag WHERE club_id = :club AND name IN (\'SENIOR\', \'COMPETITION\'))',
            ['club' => $club->getId()],
        );
        $connection->executeStatement(
            'DELETE FROM team_tag WHERE club_id = :club AND name IN (\'SENIOR\', \'COMPETITION\')',
            ['club' => $club->getId()],
        );
        $this->em->clear();

        // Pré-état : SENIOR et COMPETITION ne visent personne.
        $this->resolver->reset();
        self::assertNotContains($teamId, $this->resolver->tagTeamIds('SENIOR', $season->getId(), $club->getId()), 'témoin : SENIOR absent avant resync');
        self::assertNotContains($teamId, $this->resolver->tagTeamIds('COMPETITION', $season->getId(), $club->getId()), 'témoin : COMPETITION absent avant resync');

        // La commande.
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:team-tags:resync'));
        $tester->execute(['--club' => $club->getId()]);
        $tester->assertCommandIsSuccessful();

        // Les nouveaux tags visent désormais l'équipe, sans qu'on ait réécrit l'équipe.
        $this->scopeGucToClub($club->getId());
        $this->resolver->reset();
        self::assertContains($teamId, $this->resolver->tagTeamIds('SENIOR', $season->getId(), $club->getId()), 'resync pose SENIOR (+ de 22)');
        self::assertContains($teamId, $this->resolver->tagTeamIds('COMPETITION', $season->getId(), $club->getId()), 'resync pose COMPETITION');
        self::assertContains($teamId, $this->resolver->tagTeamIds('ADULTE', $season->getId(), $club->getId()), 'ADULTE reste posé');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(TeamTagResolver::class);
    }
}
