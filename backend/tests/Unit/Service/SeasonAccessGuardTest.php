<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\SeasonAccessGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le garde prend la PLUS STRICTE de deux saisons : celle du header (attribut
 * `_season_readonly`, comportement d'origine — transition PR-3) ET celle de la
 * RESSOURCE ciblée (`$targetSeasonId`, dérivée hors filtres — SEC-13). Ce test
 * falsifie les deux branches ; le chemin HTTP est couvert par SeasonReadonlyTest.
 *
 * Horloge figée au 2026-08-21 : pivot 15-juillet ⇒ saison courante 2026, 2025
 * archivée, 2027 brouillon (writable).
 */
#[Group('unit')]
final class SeasonAccessGuardTest extends TestCase
{
    private const string CLUB_ID = '11111111-1111-4111-8111-111111111111';
    private const string PAST_ID = '22222222-2222-4222-8222-222222222222';
    private const string CURRENT_ID = '33333333-3333-4333-8333-333333333333';
    private const string DRAFT_ID = '44444444-4444-4444-8444-444444444444';
    private const string UNKNOWN_ID = '55555555-5555-4555-8555-555555555555';

    // --- Branche 1 : saison SÉLECTIONNÉE (attribut `_season_readonly`, transition PR-3) ---

    public function testThrows409WhenSeasonIsReadonly(): void
    {
        $request = new Request;
        $request->attributes->set('_season_readonly', true);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Cette saison est archivée — elle est en lecture seule.');
        $this->guard()->assertWritable($request);
    }

    public function testAllowsWhenNotReadonly(): void
    {
        $request = new Request;
        $request->attributes->set('_season_readonly', false);

        $this->guard()->assertWritable($request);
        $this->addToAssertionCount(1); // no exception = writable
    }

    public function testAllowsWhenAttributeAbsent(): void
    {
        $this->guard()->assertWritable(new Request);
        $this->addToAssertionCount(1);
    }

    public function testAllowsWhenRequestIsNull(): void
    {
        // Non-HTTP context (CLI/worker) → no request, never blocks.
        $this->guard()->assertWritable(null);
        $this->addToAssertionCount(1);
    }

    // --- Branche 2 : saison de la RESSOURCE ciblée (SEC-13) ---

    public function testArchivedTargetSeasonThrowsEvenWhenSelectedSeasonIsWritable(): void
    {
        // Requête SANS `_season_readonly` (saison sélectionnée courante, writable),
        // mais la cible vit dans la saison archivée → 409, message byte-identique.
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Cette saison est archivée — elle est en lecture seule.');

        $this->guard()->assertWritable(new Request, self::PAST_ID);
    }

    public function testCurrentTargetSeasonPasses(): void
    {
        $this->guard()->assertWritable(new Request, self::CURRENT_ID);
        $this->addToAssertionCount(1); // aucune exception
    }

    public function testFutureTargetSeasonPasses(): void
    {
        // La dérivation ne sur-verrouille pas le futur (brouillon N+1).
        $this->guard()->assertWritable(new Request, self::DRAFT_ID);
        $this->addToAssertionCount(1);
    }

    public function testUnknownTargetSeasonDoesNotThrow(): void
    {
        // Cible introuvable (RLS la cache / id d'un autre club) → repli, JAMAIS un
        // oracle d'existence : le garde ne 409 pas.
        $this->guard()->assertWritable(new Request, self::UNKNOWN_ID);
        $this->addToAssertionCount(1);
    }

    private function guard(): SeasonAccessGuard
    {
        $past = $this->season(self::PAST_ID, '2025-08-01');
        $current = $this->season(self::CURRENT_ID, '2026-08-01');
        $draft = $this->season(self::DRAFT_ID, '2027-08-01');

        // EntityManagerInterface + EntityRepository sont mockables (l'un est une
        // interface, l'autre n'est pas `final`) — au contraire de SeasonRepository /
        // SeasonResolver. Le read-only se calcule ensuite par le pur
        // SeasonResolver::isReadonlyAmong, sans base.
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$past, $current, $draft]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class, mixed $id): ?Season => match ($id) {
            self::PAST_ID => $past,
            self::CURRENT_ID => $current,
            self::DRAFT_ID => $draft,
            default => null,
        });
        $em->method('getRepository')->willReturn($repository);

        return new SeasonAccessGuard($em, new MockClock(new DateTimeImmutable('2026-08-21')));
    }

    private function season(string $id, string $startDate): Season
    {
        $season = new Season;
        $season->setId($id);
        $season->setClubId(self::CLUB_ID);
        $season->setName($startDate);
        $season->setStartDate(new DateTimeImmutable($startDate));
        $season->setEndDate(new DateTimeImmutable($startDate));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);

        return $season;
    }
}
