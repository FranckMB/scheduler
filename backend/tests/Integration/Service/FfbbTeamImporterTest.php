<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbEngagementReader;
use App\Service\Basketball\FfbbTeamImporter;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-21 lot A — la création automatique des équipes engagées à l'onboarding.
 * FFBB simulée en MockHttpClient (patron FfbbClubPopulatorTest) : un jeu
 * BCCL-like — senior structuré, jeune « Brassage » à décoder, coupe à sauter.
 */
#[Group('integration')]
final class FfbbTeamImporterTest extends KernelTestCase
{
    use TenantGucTrait;

    private const CLUB_CODE = 'ARA0069036';

    private EntityManagerInterface $em;

    private Club $club;

    public function testCreatesTeamsFromEngagementsWithDecodeRankingAndNames(): void
    {
        $importer = $this->buildImporter();

        self::assertSame(3, $importer->importEngagedTeams($this->club));

        /** @var list<Team> $teams */
        $teams = $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()], ['tierOrder' => 'ASC']);
        self::assertCount(3, $teams);

        // Rang S (PN) d'abord — le fanion se détecte par le NIVEAU.
        self::assertSame('SM1', $teams[0]->getName());
        self::assertSame(1, $teams[0]->getPriorityTierId());
        self::assertSame('NATIONAL', $teams[0]->getLevel()?->value);
        self::assertSame('M', $teams[0]->getGender()?->value);
        self::assertSame(2, $teams[0]->getSessionsPerWeek(), 'décision fondateur : 2 séances par défaut');

        // Le jeune « RMU13 Brassage » : champs structurés VIDES, tout vient du
        // décodage du code (décision fondateur : R/M/U13) → catégorie U13 du club.
        self::assertSame('U13M1', $teams[1]->getName());
        self::assertSame(2, $teams[1]->getPriorityTierId(), 'R → rang A');
        self::assertSame('REGIONAL', $teams[1]->getLevel()?->value);

        // La sénior D : rang C, nom féminin.
        self::assertSame('SF1', $teams[2]->getName());
        self::assertSame(4, $teams[2]->getPriorityTierId());
    }

    public function testNonEmptyClubIsNeverTouched(): void
    {
        // La borne du cadrage : la moindre équipe existante → no-op total.
        $existing = (new Team)->setClubId($this->club->getId())->setSeasonId($this->seasonId())
            ->setSportCategoryId($this->categoryId('Senior'))->setName('Ma saisie')->setPriorityTierId(1);
        $this->em->persist($existing);
        $this->em->flush();

        self::assertSame(0, $this->buildImporter()->importEngagedTeams($this->club));
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Import ' . $uid)->setSlug('imp-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(false)
            ->setFfbbClubCode(self::CLUB_CODE);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable);
        $season = (new Season)->setClubId($this->club->getId())->setName($year . '-' . ($year + 1))
            ->setStartDate(new DateTimeImmutable($year . '-07-15'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-14'))
            ->setStatus('active')->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        foreach ([['Senior', 22, 99], ['U13', 12, 13]] as [$name, $min, $max]) {
            $category = (new SportCategory)->setClubId($this->club->getId())->setSportId($sport->getId())->setName($name)->setAgeMin($min)->setAgeMax($max);
            $this->em->persist($category);
        }
        $this->em->flush();
    }

    private function seasonId(): string
    {
        return (string) $this->em->getRepository(Season::class)->findOneBy(['clubId' => $this->club->getId()])?->getId();
    }

    private function categoryId(string $name): string
    {
        return (string) $this->em->getRepository(SportCategory::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => $name])?->getId();
    }

    private function buildImporter(): FfbbTeamImporter
    {
        $seasonCode = \sprintf('%02d-%02d', SeasonResolver::seasonYear(new DateTimeImmutable) % 100, (SeasonResolver::seasonYear(new DateTimeImmutable) + 1) % 100);
        $engagements = [
            // Sénior structuré (les champs FFBB remplis — le cas 7/14).
            ['codeClub' => self::CLUB_CODE, 'sexe' => 'Masculin', 'categorie' => ['libelle' => 'Seniors'],
                'idCompetition' => ['id' => 'C-PNM', 'code' => 'PNM', 'nom' => 'Pré nationale masculine'], 'idPoule' => ['id' => 'P-PNM', 'nom' => 'Poule D']],
            // Jeune « Brassage » : structuré VIDE — tout vient du code (décision fondateur).
            ['codeClub' => self::CLUB_CODE, 'sexe' => null, 'categorie' => null,
                'idCompetition' => ['id' => 'C-RMU13', 'code' => 'RMU13', 'nom' => 'RMU13 Brassage'], 'idPoule' => ['id' => 'P-RMU13', 'nom' => 'Poule A']],
            // Sénior féminine départementale.
            ['codeClub' => self::CLUB_CODE, 'sexe' => 'Féminin', 'categorie' => ['libelle' => 'Seniors'],
                'idCompetition' => ['id' => 'C-DF2', 'code' => 'DF2', 'nom' => 'Départementale féminine seniors'], 'idPoule' => ['id' => 'P-DF2', 'nom' => 'Poule A']],
            // Coupe : code indéchiffrable ET structuré vide → SAUTÉE, jamais devinée.
            ['codeClub' => self::CLUB_CODE, 'sexe' => null, 'categorie' => null,
                'idCompetition' => ['id' => 'C-CUP', 'code' => 'CRMLU17M', 'nom' => 'Coupe U17M'], 'idPoule' => ['id' => 'P-CUP', 'nom' => '1/8']],
        ];
        $competitions = [];
        foreach ($engagements as $e) {
            $competitions[$e['idCompetition']['code']] = [
                'id' => $e['idCompetition']['id'], 'code' => $e['idCompetition']['code'], 'nom' => $e['idCompetition']['nom'],
                'saison' => ['code' => $seasonCode],
                'poules' => [['id' => $e['idPoule']['id'], 'nom' => $e['idPoule']['nom'], 'engagements' => [['nom' => 'ADVERSAIRE UN'], ['nom' => 'ADVERSAIRE DEUX']]]],
            ];
        }

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($engagements, $competitions): MockResponse {
            if (str_contains($url, 'configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 't']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            if (str_contains($body, 'ffbbserver_engagements')) {
                return new MockResponse((string) json_encode(['results' => [['hits' => $engagements]]]));
            }
            if (str_contains($body, 'ffbbserver_competitions')) {
                foreach ($competitions as $code => $row) {
                    if (str_contains($body, (string) $code)) {
                        return new MockResponse((string) json_encode(['results' => [['hits' => [$row]]]]));
                    }
                }
            }

            return new MockResponse((string) json_encode(['results' => [['hits' => []]]]));
        });

        $api = new FfbbApiClient($http);
        $reader = new FfbbEngagementReader($api);

        return new FfbbTeamImporter($this->em, $reader, self::getContainer()->get(SeasonResolver::class), new NullLogger);
    }
}
