<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\TeamTagResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — SÉMANTIQUE DE CONTRAINTE (axe structurant §7.1).
 *
 * Une contrainte ciblant une tranche d'âge doit atteindre EXACTEMENT les équipes de cette
 * tranche. Le tag n'est pas un libellé : `ScheduleConstraintBuilder::resolveTagToTeamIds`
 * l'éclate en N contraintes par équipe dans le payload du solveur, donc sa portée EST ce
 * que le solveur applique. Élargir un tag d'une catégorie, c'est imposer en silence une
 * règle à des équipes que le gestionnaire n'a jamais visées ; le rétrécir, c'est la retirer
 * à des équipes qu'il croit couvertes.
 *
 * ⚠ Ce test garde la FRONTIÈRE, là où le doute vit. Avant P4-42, `determineTagNames`
 * taguait EMB toute catégorie d'`ageMax <= 12` — U5 et U7 compris — alors que l'écran
 * annonçait « EMB (U9-U11) » (`tagLabels.ts`). Aucun test ne couvrait cette limite : on
 * pouvait la déplacer d'une année sans qu'une seule assertion rougisse.
 *
 * ⚠ Et « Baby basket » n'a AUCUN âge au catalogue : il ne portait donc aucun tag de
 * tranche et restait invisible à toute contrainte par âge. Sa présence sous BABY se
 * dérive de son NOM — un chemin distinct, qui mérite donc sa propre assertion.
 */
#[Group('phase1')]
#[Group('integration')]
final class TeamTagScopeTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TeamTagResolver $resolver;

    private Club $club;

    private Season $season;

    private Sport $sport;

    private PriorityTier $priorityTier;

    /** @var array<string, Team> nom de catégorie → l'équipe créée dedans */
    private array $teams = [];

    public function testEmbCoversU9AndU11Only(): void
    {
        // LE cas du lot. U5/U7 en sortent, U13 n'y est jamais entré (son `ageMax` vaut 13).
        self::assertSame(
            $this->sortedIds(['U9', 'U11']),
            $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()),
            'EMB doit valoir U9+U11 — ni plus (U5/U7), ni moins',
        );
    }

    public function testBabyCoversU5U7AndTheAgelessBabyCategory(): void
    {
        self::assertSame(
            $this->sortedIds(['U5', 'U7', 'Baby basket']),
            $this->resolver->tagTeamIds('BABY', $this->season->getId(), $this->club->getId()),
            'BABY doit valoir U5+U7 ET « Baby basket », que seul son nom rattache',
        );
    }

    public function testU13StaysYoungAndIsNeverBabyNorEmb(): void
    {
        // La borne EMB n'a pas glissé d'un cran en descendant : U13 reste JEUNE. Sans cette
        // assertion, remplacer `<= 7` par `<= 13` passerait les deux tests ci-dessus.
        $u13 = $this->teams['U13']->getId();
        self::assertContains($u13, $this->resolver->tagTeamIds('JEUNE', $this->season->getId(), $this->club->getId()));
        self::assertNotContains($u13, $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()));
        self::assertNotContains($u13, $this->resolver->tagTeamIds('BABY', $this->season->getId(), $this->club->getId()));
    }

    public function testLoisirHasNoAgeBracketTagAtAll(): void
    {
        // « Loisir » n'a pas d'âge NON PLUS, et son nom ne dit rien : il ne doit tomber dans
        // aucune tranche. C'est ce qui prouve que la règle de nom vise « baby » et n'avale
        // pas toute catégorie sans âge.
        $loisir = $this->teams['Loisir']->getId();
        foreach (['BABY', 'EMB', 'JEUNE', 'SENIOR'] as $bracket) {
            self::assertNotContains(
                $loisir,
                $this->resolver->tagTeamIds($bracket, $this->season->getId(), $this->club->getId()),
                \sprintf('« Loisir » ne doit pas tomber dans %s', $bracket),
            );
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->resolver = $container->get(TeamTagResolver::class);

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Tag Scope Club');
        $this->club->setSlug('tag-scope-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->sport = new Sport;
        $this->sport->setName('Basketball');
        $this->sport->setSlug('bball-tag-' . $uid);
        $this->sport->setIsActive(true);
        $this->em->persist($this->sport);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus('active');
        $this->em->persist($this->season);

        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            $this->priorityTier = $existing;
        } else {
            $this->priorityTier = new PriorityTier;
            $this->priorityTier->setId(1);
            $this->priorityTier->setLabel('S');
            $this->priorityTier->setName('Senior');
            $this->priorityTier->setColor('#FF0000');
            $this->priorityTier->setOrToolsWeight(100);
            $this->priorityTier->setDefaultMinSessions(2);
            $this->em->persist($this->priorityTier);
        }
        $this->em->flush();

        // Les âges sont ceux du VRAI catalogue (`Service\Basketball\CategoryCatalog`) — un
        // jeu inventé testerait une frontière qui n'existe nulle part. « Baby basket » et
        // « Loisir » y sont bien sans âge, c'est le fait qui compte ici.
        foreach ([
            ['U5', 3, 5],
            ['U7', 6, 7],
            ['U9', 8, 9],
            ['U11', 10, 11],
            ['U13', 12, 13],
            ['Baby basket', null, null],
            ['Loisir', null, null],
        ] as [$name, $ageMin, $ageMax]) {
            $this->teams[$name] = $this->createTeamInCategory($name, $ageMin, $ageMax);
        }

        // Le mémo de `TeamTagResolver` est par requête/message : il aurait pu figer une
        // réponse d'avant la création des équipes. On repart propre.
        $this->resolver->reset();
    }

    /**
     * Crée la catégorie et son équipe. Les tags sont DÉRIVÉS : `TeamTagSyncListener`
     * les pose au postFlush de la Team — on ne les écrit jamais à la main, sans quoi le
     * test vérifierait ses propres fixtures au lieu de la règle.
     */
    private function createTeamInCategory(string $categoryName, ?int $ageMin, ?int $ageMax): Team
    {
        $this->scopeGucToClub($this->club->getId());

        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($this->sport->getId());
        $category->setName($categoryName);
        $category->setAgeMin($ageMin);
        $category->setAgeMax($ageMax);
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId($this->priorityTier->getId());
        $team->setName($categoryName . ' 1');
        $team->setSessionsPerWeek(1);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * Les ids attendus dans l'ordre du résolveur — `tagTeamIds` trie, et ce tri fait partie
     * de son contrat (il ordonne l'expansion du payload, donc le hash calculé dessus).
     *
     * @param list<string> $categoryNames
     *
     * @return list<string>
     */
    private function sortedIds(array $categoryNames): array
    {
        $ids = array_map(fn (string $name): string => $this->teams[$name]->getId(), $categoryNames);
        sort($ids);

        return $ids;
    }
}
