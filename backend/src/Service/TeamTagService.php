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
use Doctrine\ORM\EntityManagerInterface;

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
            // P4-42. Pas de migration : un club antérieur gagne le tag à sa prochaine
            // écriture d'équipe. ⚠ « Idempotent » au sens séquentiel SEULEMENT — il n'y a
            // aucun index unique sur `(club_id, name)` (`Version20260615010708` ne pose que
            // `idx_team_tag_club`), donc deux écritures de Team concurrentes sur un même
            // club peuvent insérer le tag DEUX fois. Le résolveur prend alors l'une des
            // deux lignes tandis que les assignations se répartissent : la contrainte
            // n'atteint qu'une partie des équipes, sans erreur. Mécanisme préexistant, mais
            // cette ligne rouvre la fenêtre une fois par club (revue #352) → P4-64.
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

        foreach ($requiredTags as $name => $color) {
            if (!isset($tags[$name])) {
                $tag = new TeamTag;
                $tag->setClubId($clubId);
                $tag->setName($name);
                $tag->setColor($color);
                $tag->setIsSystem(true);
                $tag->setAxis(self::SYSTEM_TAG_AXES[$name]);

                $this->entityManager->persist($tag);
                $tags[$name] = $tag;
            }
        }

        $this->entityManager->flush();

        return $tags;
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
