<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Enum\SeasonStatus;
use App\Enum\TeamTagAxis;
use App\Service\TeamTagResolver;
use App\Service\TeamTagService;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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

    /**
     * NR — P4-64. LA garde : la base refuse deux tags de même nom dans un club.
     *
     * Sans elle, deux écritures de `Team` concurrentes inséraient le tag deux fois, et
     * `TeamTagResolver::tagTeamIds` (`findOneBy`) en choisissait **une** pendant que les
     * assignations se répartissaient entre les deux lignes : une contrainte ciblant ce tag
     * n'atteignait qu'une partie des équipes, **sans erreur ni warning** — le tag était bien
     * trouvé. C'est le pire mode de panne, et aucun test ne le couvrait.
     */
    public function testDatabaseRefusesTwoTagsWithTheSameNameInAClub(): void
    {
        $this->scopeGucToClub($this->club->getId());

        // Le tag BABY existe déjà (posé par la dérivation au setUp) : le ré-insérer doit
        // heurter `uniq_team_tag_club_name`.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, color, is_system, axis)'
            . ' VALUES (:id, 1, NOW(), NOW(), :club, \'BABY\', \'#F5A9C8\', true, \'AGE\')',
            ['id' => Uuid::v4()->toRfc4122(), 'club' => $this->club->getId()],
        );
    }

    /**
     * NR — revue #356, LE défaut le plus grave du lot : lecture et écriture doivent s'accorder.
     *
     * L'arbitre d'écriture est `ON CONFLICT (club_id, name)`, qui ne regarde PAS `is_system`.
     * Tant que les lectures filtraient sur ce drapeau, une ligne homonyme non-système était
     * invisible à la lecture, avalée à l'insertion, absente de la relecture — et
     * `syncTeamTags` sautait l'assignation APRÈS avoir supprimé les anciennes. Éditer une
     * équipe DÉTRUISAIT donc son assignation sans jamais la recréer, en silence, et une
     * contrainte ciblant ce tag n'atteignait plus personne.
     */
    public function testANonSystemTagWithASystemNameIsUsedInsteadOfBeingLost(): void
    {
        $this->scopeGucToClub($this->club->getId());

        // On remplace le U13 système par un homonyme NON système — l'état que la migration
        // elle-même peut produire en gardant la ligne la plus ancienne.
        $this->em->getConnection()->executeStatement(
            'UPDATE team_tag SET is_system = false WHERE club_id = :club AND name = \'U13\'',
            ['club' => $this->club->getId()],
        );
        $this->em->clear();

        $equipe = $this->createTeamInCategory('U13 bis', 12, 13);

        self::assertContains(
            $equipe->getId(),
            $this->resolver->tagTeamIds('U13', $this->season->getId(), $this->club->getId()),
            'le tag homonyme doit être RÉUTILISÉ, pas ignoré — sinon l\'assignation disparaît sans bruit',
        );
    }

    /**
     * NR — P4-64. LE chemin de conflit, atteint pour de vrai (revue #356).
     *
     * Le test précédent tournait sur un club déjà semé : `$manquants` y était vide,
     * `insertMissingSystemTags` n'était JAMAIS appelé, et l'assertion « l'EM reste ouvert »
     * était vraie par vacuité. Retirer `ON CONFLICT DO NOTHING` laissait toute la suite verte.
     *
     * On appelle donc la méthode DIRECTEMENT, sur un club dont les tags existent déjà : chaque
     * ligne de l'INSERT entre alors en conflit. C'est le seul moyen d'atteindre ce chemin de
     * façon déterministe — une vraie course entre deux transactions ne se scripte pas. La
     * réflexion est le prix ; en échange le test exerce le VRAI SQL contre le VRAI Postgres.
     */
    public function testInsertingAlreadyExistingSystemTagsIsAConflictFreeNoOp(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $avant = $this->countTags();

        $methode = new ReflectionMethod(TeamTagService::class, 'insertMissingSystemTags');
        $service = self::getContainer()->get(TeamTagService::class);

        // Les mêmes noms qu'en base, avec des ids NEUFS : sans `ON CONFLICT DO NOTHING`,
        // Postgres lève une violation d'unicité et ce test rougit.
        $methode->invoke($service, $this->club->getId(), ['BABY' => '#F5A9C8', 'EMB' => '#45B7D1']);

        self::assertSame($avant, $this->countTags(), 'aucune ligne ajoutée : le conflit est un no-op');
        self::assertTrue($this->em->isOpen(), 'l\'EntityManager reste ouvert — c\'est tout l\'intérêt du SQL natif');
    }

    /**
     * NR — P4-64. La liste de colonnes écrite à la main dans `insertMissingSystemTags` doit
     * rester alignée sur l'entité (revue #356 : le docblock promettait ce test, il n'existait
     * pas).
     *
     * Sans lui, ajouter une colonne NOT NULL à `team_tag` laisse la suite unitaire verte — elle
     * mocke la connexion — et casse en production à la première écriture d'équipe d'un club
     * neuf, qui n'obtient alors AUCUN tag système.
     */
    public function testTheInsertColumnListMatchesTheEntityMapping(): void
    {
        // ⚠ On lit la constante que le SQL UTILISE, pas une liste recopiée ici (revue #356).
        // La première version de ce test comparait deux copies : retirer une colonne de
        // l'INSERT le laissait vert — exactement le défaut qu'il était censé fermer.
        /** @var list<string> $insertees */
        $insertees = new ReflectionClass(TeamTagService::class)->getConstant('INSERT_COLUMNS');
        $colonnesEntite = $this->em->getClassMetadata(TeamTag::class)->getColumnNames();

        self::assertSame(
            [],
            array_values(array_diff($colonnesEntite, $insertees)),
            'colonne(s) de team_tag absente(s) de INSERT_COLUMNS — une NOT NULL ferait échouer toute écriture',
        );
        self::assertSame(
            [],
            array_values(array_diff($insertees, $colonnesEntite)),
            'colonne insérée qui n\'existe plus au mapping',
        );
    }

    /**
     * NR — revue #356 : le `true` de `is_system` est un littéral dans une chaîne SQL, que rien
     * n'assertait. L'écrire `false` ferait naître tous les tags système en non-système sans
     * qu'un test rougisse — les lectures ne filtrent plus sur ce drapeau (correctif round 1),
     * donc la panne ne se verrait que sur les écrans qui distinguent système et personnalisé.
     */
    public function testInsertedSystemTagsAreFlaggedAsSystem(): void
    {
        $this->scopeGucToClub($this->club->getId());

        $tag = $this->em->getRepository(TeamTag::class)
            ->findOneBy(['clubId' => $this->club->getId(), 'name' => 'BABY']);

        self::assertInstanceOf(TeamTag::class, $tag);
        self::assertTrue($tag->getIsSystem(), 'un tag semé par insertMissingSystemTags est SYSTÈME');
        self::assertSame(TeamTagAxis::AGE, $tag->getAxis(), 'et il naît avec son axe');
    }

    /**
     * NR — P4-64, l'autre moitié : la garde ne doit pas transformer la course en 500.
     *
     * ⚠ Ce test n'emprunte PAS le chemin d'insertion (revue #356) : sur un club déjà semé
     * `$manquants` est vide et `insertMissingSystemTags` n'est jamais appelé. Il garde
     * l'IDEMPOTENCE d'ensemble — rejouer la dérivation n'ajoute ni ne perd de tag, et
     * l'EntityManager reste ouvert. Le chemin de conflit lui-même est gardé par
     * `testInsertingAlreadyExistingSystemTagsIsAConflictFreeNoOp`.
     */
    public function testResyncingAnAlreadySeededClubIsAQuietNoOp(): void
    {
        $avant = $this->countSystemTags();

        // Une écriture de Team de plus → le listener rejoue `syncTeamTags`, donc
        // `getOrCreateSystemTags`, sur un club qui a déjà tous ses tags.
        $this->createTeamInCategory('U15', 14, 15);

        self::assertSame($avant, $this->countSystemTags(), 'aucun tag système en double, aucun tag perdu');
        self::assertTrue($this->em->isOpen(), 'l\'EntityManager doit rester ouvert — un flush en violation le fermerait');
    }

    /**
     * NR — P4-50 (b). ÉDITER LA CATÉGORIE DOIT DÉPLACER LES ÉQUIPES, pas les laisser en arrière.
     *
     * Les tags sont dérivés du NOM et des bornes d'âge de la `SportCategory`
     * (`TeamTagService::determineTagNames`), mais `TeamTagSyncListener` n'écoutait que
     * `Team` : corriger une catégorie — geste normal, on ne retouche pas les équipes —
     * laissait leurs tags périmés, et aucun écran ne le disait.
     *
     * Le scénario est celui du terrain : la catégorie a été créée « U11 » par erreur, elle
     * est en réalité U13. Après correction, une contrainte « groupe U13 » DOIT atteindre
     * l'équipe — sans le correctif elle n'atteint personne, pendant qu'une contrainte
     * « U11 » frappe une équipe qui n'est plus U11. Génération `COMPLETED`, zéro
     * diagnostic, planning faux : le pire mode de panne.
     *
     * ⚠ Les quatre assertions comptent, et les NÉGATIVES autant que les positives. Un
     * correctif qui ajouterait les nouveaux tags sans retirer les anciens (par exemple en
     * n'appelant pas `syncTeamTags`, qui purge d'abord) laisserait l'équipe dans les DEUX
     * groupes — vert sur les positives seules.
     */
    public function testEditingACategoryRetagsItsTeams(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $equipe = $this->teams['U11']->getId();

        // Pré-état : l'équipe est bien U11+EMB. Sans ce témoin, un test vert ne prouverait
        // pas que quelque chose a BOUGÉ — il pourrait l'être depuis toujours.
        self::assertContains($equipe, $this->resolver->tagTeamIds('U11', $this->season->getId(), $this->club->getId()));
        self::assertContains($equipe, $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()));

        $categorie = $this->em->getRepository(SportCategory::class)->find($this->teams['U11']->getSportCategoryId());
        self::assertInstanceOf(SportCategory::class, $categorie);
        $categorie->setName('U13');
        $categorie->setAgeMin(12);
        $categorie->setAgeMax(13);
        // AUCUNE écriture sur la Team : c'est tout l'objet du test.
        $this->em->flush();

        // Le mémo du résolveur est par requête : il a mémorisé l'état d'AVANT.
        $this->resolver->reset();

        self::assertContains(
            $equipe,
            $this->resolver->tagTeamIds('U13', $this->season->getId(), $this->club->getId()),
            'la catégorie corrigée en U13 doit retaguer son équipe — sinon une contrainte « groupe U13 » ne l\'atteint pas',
        );
        self::assertContains(
            $equipe,
            $this->resolver->tagTeamIds('JEUNE', $this->season->getId(), $this->club->getId()),
            'U13 a ageMax 13 : la tranche passe d\'EMB à JEUNE',
        );
        self::assertNotContains(
            $equipe,
            $this->resolver->tagTeamIds('U11', $this->season->getId(), $this->club->getId()),
            'l\'ancien tag doit être RETIRÉ — sinon une contrainte « groupe U11 » frappe une équipe qui n\'en est plus',
        );
        self::assertNotContains(
            $equipe,
            $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()),
            'l\'ancienne tranche doit être retirée elle aussi',
        );
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
        $this->season->setStatus(SeasonStatus::ACTIVE);
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

    private function countTags(): int
    {
        $this->scopeGucToClub($this->club->getId());

        return \count($this->em->getRepository(TeamTag::class)->findBy(['clubId' => $this->club->getId()]));
    }

    private function countSystemTags(): int
    {
        $this->scopeGucToClub($this->club->getId());

        return \count($this->em->getRepository(TeamTag::class)->findBy([
            'clubId' => $this->club->getId(),
            'isSystem' => true,
        ]));
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
