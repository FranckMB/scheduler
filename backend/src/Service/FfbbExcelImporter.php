<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class FfbbExcelImporter
{
    private const DEFAULT_PRIORITY_TIER_ID = 5;
    private const DEFAULT_SESSIONS_PER_WEEK = 2;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function import(string $filePath, string $clubId, string $seasonId): array
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            throw new \InvalidArgumentException('Club not found.');
        }

        $expectedClubCode = $club->getFfbbClubCode();
        if (null === $expectedClubCode || '' === $expectedClubCode) {
            throw new \InvalidArgumentException('Club does not have an FFBB club code configured.');
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if ([] === $rows || $this->isEmptyRow($rows[0] ?? [])) {
            return ['created' => 0, 'skipped' => 0, 'errors' => ['Excel file is empty.']];
        }

        $header = array_shift($rows);
        $columnMap = $this->buildColumnMap($header);

        if (!isset($columnMap['nom'], $columnMap['catégorie'], $columnMap['numéro'], $columnMap['organisme'])) {
            throw new \InvalidArgumentException('Required columns missing: Nom, Catégorie, Numéro, Organisme.');
        }

        $sport = $this->findDefaultSport();
        if (!$sport instanceof Sport) {
            throw new \RuntimeException('No default sport found for category creation.');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            /** @var array<mixed> $row */
            $nom = $this->stringValue($row[$columnMap['nom']] ?? null);
            $categorie = $this->stringValue($row[$columnMap['catégorie']] ?? null);
            $numero = $this->stringValue($row[$columnMap['numéro']] ?? null);
            $organisme = $this->stringValue($row[$columnMap['organisme']] ?? null);

            if ('' === $nom || '' === $categorie || '' === $numero || '' === $organisme) {
                continue;
            }

            $extractedClubCode = $this->extractClubCode($organisme);
            if (null === $extractedClubCode) {
                $errors[] = sprintf('Row %d: unable to extract club code from Organisme.', $rowIndex + 2);
                continue;
            }

            if ($extractedClubCode !== $expectedClubCode) {
                throw new \RuntimeException(sprintf('Identity theft prevention: extracted club code "%s" does not match club code "%s".', $extractedClubCode, $expectedClubCode));
            }

            $existingTeam = $this->entityManager->getRepository(Team::class)->findOneBy([
                'clubId' => $clubId,
                'seasonId' => $seasonId,
                'ffbbTeamId' => $numero,
            ]);

            if ($existingTeam instanceof Team) {
                ++$skipped;
                continue;
            }

            $sportCategory = $this->findOrCreateSportCategory($categorie, $clubId, $sport->getId());

            $team = (new Team())
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSportCategoryId($sportCategory->getId())
                ->setPriorityTierId(self::DEFAULT_PRIORITY_TIER_ID)
                ->setName($nom)
                ->setSessionsPerWeek(self::DEFAULT_SESSIONS_PER_WEEK)
                ->setIsCompetition(true)
                ->setIsActive(true)
                ->setFfbbTeamId($numero);

            $this->entityManager->persist($team);
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<mixed> $header
     *
     * @return array<string, int>
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $value) {
            $value = $this->stringValue($value);
            if ('' === $value) {
                continue;
            }
            $key = mb_strtolower($value, 'UTF-8');
            $map[$key] = $index;
        }

        return $map;
    }

    private function stringValue(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function extractClubCode(string $organisme): ?string
    {
        $parts = explode(' - ', $organisme, 2);
        $code = trim($parts[0]);

        return '' !== $code ? $code : null;
    }

    private function findDefaultSport(): ?Sport
    {
        return $this->entityManager->getRepository(Sport::class)->findOneBy(['isActive' => true]);
    }

    private function findOrCreateSportCategory(string $name, string $clubId, string $sportId): SportCategory
    {
        $repository = $this->entityManager->getRepository(SportCategory::class);

        $category = $repository->findOneBy([
            'name' => $name,
            'clubId' => $clubId,
            'sportId' => $sportId,
        ]);

        if ($category instanceof SportCategory) {
            return $category;
        }

        $category = $repository->findOneBy([
            'name' => $name,
            'clubId' => null,
            'sportId' => $sportId,
        ]);

        if ($category instanceof SportCategory) {
            return $category;
        }

        $category = (new SportCategory())
            ->setName($name)
            ->setClubId($clubId)
            ->setSportId($sportId)
            ->setIsCustom(true)
            ->setSortOrder(0);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    /** @param array<mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (null !== $cell && '' !== $cell) {
                return false;
            }
        }

        return true;
    }
}
