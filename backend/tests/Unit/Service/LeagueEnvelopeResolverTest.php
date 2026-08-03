<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\LeagueMatchWindow;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Service\LeagueEnvelopeResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Portage serveur de la jointure tolérante d'`envelope.ts` (P1-4 PR D) : mêmes
 * règles que l'écran — catégorie normalisée, niveau/genre exigés CONNUS et
 * égaux, fenêtre sans genre = catalogue entier ; non résolu = [] (aucun HARD).
 */
#[Group('unit')]
final class LeagueEnvelopeResolverTest extends TestCase
{
    public function testMapsOnNormalizedCategoryLevelAndGender(): void
    {
        $team = $this->team('team-1', 'cat-senior', TeamLevel::DEPARTEMENTAL, Gender::F);
        $category = $this->category('cat-senior', 'Sénior'); // accent — normalized join
        $windows = [
            $this->window('SENIOR', 'DEPARTEMENTAL', null, 6),
            $this->window('SENIOR', 'DEPARTEMENTAL', 'M', 7), // gendered, other gender
            $this->window('U13', 'DEPARTEMENTAL', null, 6),
        ];

        $resolved = new LeagueEnvelopeResolver()->resolve([$team], [$category], $windows);

        self::assertCount(1, $resolved['team-1']);
        self::assertSame(6, $resolved['team-1'][0]->getDayOfWeek());
    }

    public function testUnknownLevelNeverMatchesAnyWindow(): void
    {
        // envelope.ts rule: an unknown axis must NOT match every window.
        $team = $this->team('team-1', 'cat-senior', null, Gender::F);
        $category = $this->category('cat-senior', 'SENIOR');

        $resolved = new LeagueEnvelopeResolver()->resolve(
            [$team],
            [$category],
            [$this->window('SENIOR', 'DEPARTEMENTAL', null, 6)],
        );

        self::assertSame([], $resolved['team-1']);
    }

    public function testGenderedWindowRequiresTheMatchingGender(): void
    {
        $team = $this->team('team-1', 'cat-u18', TeamLevel::REGIONAL, Gender::M);
        $category = $this->category('cat-u18', 'U18');
        $windows = [
            $this->window('U18', 'REGIONAL', 'M', 7),
            $this->window('U18', 'REGIONAL', null, 6),
        ];

        $resolved = new LeagueEnvelopeResolver()->resolve([$team], [$category], $windows);

        self::assertCount(2, $resolved['team-1']); // gendered M + catalog-wide
    }

    private function team(string $id, string $categoryId, ?TeamLevel $level, ?Gender $gender): Team
    {
        $team = new Team;
        $property = new ReflectionProperty(Team::class, 'id');
        $property->setValue($team, $id);
        $team->setClubId('club');
        $team->setSeasonId('season');
        $team->setSportCategoryId($categoryId);
        $team->setName('T');
        $team->setLevel($level);
        $team->setGender($gender);

        return $team;
    }

    private function category(string $id, string $name): SportCategory
    {
        $category = new SportCategory;
        $property = new ReflectionProperty(SportCategory::class, 'id');
        $property->setValue($category, $id);
        $category->setClubId('club');
        $category->setSportId('sport');
        $category->setName($name);

        return $category;
    }

    private function window(string $category, string $level, ?string $gender, int $day): LeagueMatchWindow
    {
        $window = new LeagueMatchWindow;
        $window->setLeague('AURA');
        $window->setCategory($category);
        $window->setLevel($level);
        $window->setGender($gender);
        $window->setDayOfWeek($day);
        $window->setKickoffMin(new DateTimeImmutable('14:00'));
        $window->setKickoffMax(new DateTimeImmutable('18:00'));

        return $window;
    }
}
