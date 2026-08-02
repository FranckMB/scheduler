<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Enum\TeamTagAxis;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TeamTagService
{
    /** Deterministic axis of each system tag, for the constraint target grouping (Lot B). */
    private const SYSTEM_TAG_AXES = [
        'FEMININE' => TeamTagAxis::GENRE, 'MASCULINE' => TeamTagAxis::GENRE, 'MIXTE' => TeamTagAxis::GENRE,
        'BABY' => TeamTagAxis::AGE, 'EMB' => TeamTagAxis::AGE, 'JEUNE' => TeamTagAxis::AGE, 'SENIOR' => TeamTagAxis::AGE,
        'U9' => TeamTagAxis::AGE, 'U11' => TeamTagAxis::AGE, 'U13' => TeamTagAxis::AGE,
        'U15' => TeamTagAxis::AGE, 'U18' => TeamTagAxis::AGE, 'U21' => TeamTagAxis::AGE,
        'ELITE' => TeamTagAxis::NIVEAU, 'REGIONAL' => TeamTagAxis::NIVEAU, 'NATIONAL' => TeamTagAxis::NIVEAU,
        'DEPARTEMENTAL' => TeamTagAxis::NIVEAU, 'LOISIR_ADULTE' => TeamTagAxis::NIVEAU, 'LOISIR_JEUNE' => TeamTagAxis::NIVEAU,
        'HONNEUR' => TeamTagAxis::NIVEAU, 'PROMOTION' => TeamTagAxis::NIVEAU, 'PRE_REGION' => TeamTagAxis::NIVEAU,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function syncTeamTags(Team $team, string $seasonId): void
    {
        $clubId = $team->getClubId();
        $teamId = $team->getId();

        // Remove existing assignments for this team/season
        $existingAssignments = $this->entityManager->getRepository(TeamTagAssignment::class)->findBy([
            'teamId' => $teamId,
            'seasonId' => $seasonId,
        ]);

        foreach ($existingAssignments as $assignment) {
            $this->entityManager->remove($assignment);
        }

        // Get or create system tags for this club
        $systemTags = $this->getOrCreateSystemTags($clubId);

        // Determine which tags apply to this team
        $tagNames = $this->determineTagNames($team);

        // Create assignments
        foreach ($tagNames as $tagName) {
            if (!isset($systemTags[$tagName])) {
                continue;
            }

            $assignment = new TeamTagAssignment;
            $assignment->setTeamId($teamId);
            $assignment->setTagId($systemTags[$tagName]->getId());
            $assignment->setSeasonId($seasonId);

            $this->entityManager->persist($assignment);
        }
    }

    /**
     * U5-U7, la tranche que le fondateur veut distinguer d'EMB (P4-42).
     *
     * Deux chemins, et l'ÂGE prime quand il est connu : `ageMax <= 7` couvre U5 (3-5) et
     * U7 (6-7), U9 commençant à 8.
     *
     * Le nom ne sert que lorsque `ageMax` est absent, et c'est le cas de « Baby basket » :
     * ses bornes sont `null` au catalogue, si bien que les trois branches d'âge étaient
     * fausses et qu'il n'avait AUCUN tag de tranche — invisible à toute contrainte par âge,
     * alors que le fondateur le tient pour du U5/U7. On le reconnaît donc comme les tags
     * U9…U21 le sont déjà : au nom de sa catégorie.
     *
     * ⚠ La bascule se fait sur `ageMax` SEUL, et non « aucune des deux bornes ». L'API rend
     * `ageMin` et `ageMax` indépendamment optionnels (`SportCategoryInput`) : exiger les
     * deux nulles laissait « Baby basket » renseigné du seul `ageMin` tomber hors de TOUTES
     * les branches — le trou même que ce lot vient boucher (revue #352). `ageMax` est de
     * toute façon le discriminant des branches voisines.
     *
     * ⚠ L'âge d'abord, délibérément : une catégorie personnalisée nommée « Baby … » mais
     * dotée d'un `ageMax` réel doit suivre son âge, pas son nom.
     *
     * ⚠ Et surtout : on ne DONNE PAS d'âges à « Baby basket » pour s'épargner ce test. Ça
     * l'enrôlerait dans la règle solveur d'âge croissant, qui exempte exprès les catégories
     * sans âge (`engine/app/solver/constraints.py`, « Loisir, Baby »), et il faudrait migrer
     * le catalogue recopié chez chaque club à l'inscription.
     */
    private function isBabyCategory(string $name, ?int $ageMax): bool
    {
        if (null !== $ageMax) {
            return $ageMax <= 7;
        }

        return str_contains(mb_strtolower($name, 'UTF-8'), 'baby');
    }

    /**
     * @return array<string, TeamTag>
     */
    private function getOrCreateSystemTags(string $clubId): array
    {
        $repository = $this->entityManager->getRepository(TeamTag::class);
        $existingTags = $repository->findBy([
            'clubId' => $clubId,
            'isSystem' => true,
        ]);

        /** @var array<string, TeamTag> $tags */
        $tags = [];
        foreach ($existingTags as $tag) {
            $tags[$tag->getName()] = $tag;
            // Backfill the axis on a pre-Lot-B tag (idempotent).
            if (null === $tag->getAxis() && isset(self::SYSTEM_TAG_AXES[$tag->getName()])) {
                $tag->setAxis(self::SYSTEM_TAG_AXES[$tag->getName()]);
            }
        }

        $requiredTags = [
            'JEUNE' => '#FF6B6B',
            'SENIOR' => '#4ECDC4',
            'EMB' => '#45B7D1',
            // P4-42. Pas de migration : un club antérieur gagne le tag à sa prochaine écriture
            // d'équipe. La course qu'ouvrait cette ligne est fermée depuis P4-64 (index unique
            // + `ON CONFLICT DO NOTHING`, cf. `insertMissingSystemTags`).
            'BABY' => '#F5A9C8',
            'U9' => '#96CEB4',
            'U11' => '#FFEAA7',
            'U13' => '#DDA0DD',
            'U15' => '#98D8C8',
            'U18' => '#F7DC6F',
            'U21' => '#BB8FCE',
            'FEMININE' => '#FF69B4',
            'MASCULINE' => '#4169E1',
            'MIXTE' => '#32CD32',
            'ELITE' => '#FFD700',
            'REGIONAL' => '#C0C0C0',
            'NATIONAL' => '#CD7F32',
            'DEPARTEMENTAL' => '#87CEEB',
            'LOISIR_ADULTE' => '#98FB98',
            'LOISIR_JEUNE' => '#90EE90',
            'HONNEUR' => '#F0E68C',
            'PROMOTION' => '#DDA0DD',
            'PRE_REGION' => '#B0E0E6',
        ];

        $manquants = array_diff_key($requiredTags, $tags);
        if ([] !== $manquants) {
            $this->insertMissingSystemTags($clubId, $manquants);
        }

        // Le backfill d'axe ci-dessus est une écriture ORM ordinaire : il part avec ce flush.
        $this->entityManager->flush();

        if ([] === $manquants) {
            return $tags;
        }

        // RELECTURE obligatoire après l'insertion (P4-64). `ON CONFLICT DO NOTHING` peut avoir
        // laissé passer une ligne écrite par une transaction concurrente : l'id que NOUS avons
        // tiré n'est alors pas celui qui existe en base, et une assignation le référençant
        // pointerait dans le vide. On repart donc de ce que la base contient vraiment.
        $tags = [];
        foreach ($repository->findBy(['clubId' => $clubId, 'isSystem' => true]) as $tag) {
            $tags[$tag->getName()] = $tag;
        }

        return $tags;
    }

    /**
     * Insère les tags système absents en **une** requête, sans jamais échouer sur une course.
     *
     * P4-64 — `getOrCreateSystemTags` lisait « ce tag manque » puis le persistait : deux
     * écritures de `Team` concurrentes sur le même club inséraient le tag DEUX fois, et le
     * résolveur en choisissait ensuite une pendant que les assignations se répartissaient
     * entre les deux. Une contrainte ciblant ce tag n'atteignait alors qu'une partie des
     * équipes, **sans erreur** — le tag était bien trouvé.
     *
     * L'index unique `uniq_team_tag_club_name` interdit désormais le doublon ; reste à ne pas
     * transformer la course en erreur 500 pour le perdant. D'où `ON CONFLICT DO NOTHING` :
     * celui qui arrive second n'écrit rien et lit la ligne de l'autre.
     *
     * ⚠ SQL natif plutôt que l'ORM, délibérément : un `flush()` qui viole une contrainte
     * **ferme l'EntityManager**, ce qui perdrait aussi le travail de l'appelant (assignations,
     * backfill d'axe). L'insertion doit donc ne jamais lever. Le prix est cette liste de
     * colonnes, à tenir alignée sur l'entité — d'où le test qui les compare.
     *
     * @param array<string, string> $manquants nom du tag → couleur
     */
    private function insertMissingSystemTags(string $clubId, array $manquants): void
    {
        $now = (new DateTimeImmutable)->format('Y-m-d H:i:sP');
        $valeurs = [];
        $params = [];

        foreach (array_keys($manquants) as $i => $name) {
            $valeurs[] = \sprintf('(:id%1$d, 1, :now, :now, :club, :name%1$d, :color%1$d, true, :axis%1$d)', $i);
            $params['id' . $i] = Uuid::v4()->toRfc4122();
            $params['name' . $i] = $name;
            $params['color' . $i] = $manquants[$name];
            $params['axis' . $i] = self::SYSTEM_TAG_AXES[$name]->value;
        }

        $params['now'] = $now;
        $params['club'] = $clubId;

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, color, is_system, axis) VALUES '
            . implode(', ', $valeurs)
            . ' ON CONFLICT (club_id, name) DO NOTHING',
            $params,
        );
    }

    /**
     * @return list<string>
     */
    private function determineTagNames(Team $team): array
    {
        $tags = [];

        // Age-based tags from sport category
        $sportCategory = $this->entityManager->getRepository(SportCategory::class)->find($team->getSportCategoryId());
        if ($sportCategory instanceof SportCategory) {
            $ageMin = $sportCategory->getAgeMin();
            $ageMax = $sportCategory->getAgeMax();
            $name = $sportCategory->getName();

            // Tags de tranche d'âge. BABY passe AVANT EMB (P4-42) : U5/U7 satisfont les
            // deux bornes, c'est l'ordre qui tranche.
            if ($this->isBabyCategory($name, $ageMax)) {
                $tags[] = 'BABY';
            } elseif (null !== $ageMax && $ageMax <= 12) {
                $tags[] = 'EMB';
            } elseif (null !== $ageMax && null !== $ageMin && $ageMin <= 18) {
                $tags[] = 'JEUNE';
            } elseif (null !== $ageMin && $ageMin >= 19) {
                $tags[] = 'SENIOR';
            }

            // U-category tags from name
            if (str_contains($name, 'U9')) {
                $tags[] = 'U9';
            } elseif (str_contains($name, 'U11')) {
                $tags[] = 'U11';
            } elseif (str_contains($name, 'U13')) {
                $tags[] = 'U13';
            } elseif (str_contains($name, 'U15')) {
                $tags[] = 'U15';
            } elseif (str_contains($name, 'U18')) {
                $tags[] = 'U18';
            } elseif (str_contains($name, 'U21')) {
                $tags[] = 'U21';
            }
        }

        // Gender tags
        $gender = $team->getGender();
        if (Gender::F === $gender) {
            $tags[] = 'FEMININE';
        } elseif (Gender::M === $gender) {
            $tags[] = 'MASCULINE';
        } elseif (Gender::MIXTE === $gender) {
            $tags[] = 'MIXTE';
        }

        // Level tags
        $level = $team->getLevel();
        if ($level instanceof TeamLevel) {
            $tags[] = $level->value;
        }

        return $tags;
    }
}
