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
        'EMB' => TeamTagAxis::AGE, 'JEUNE' => TeamTagAxis::AGE, 'SENIOR' => TeamTagAxis::AGE,
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

            // Age category tags
            if (null !== $ageMax && $ageMax <= 12) {
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
