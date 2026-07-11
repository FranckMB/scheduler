<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Season;
use App\Entity\Team;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * End-to-end FBI import over HTTP (module matchs PR-4): multipart upload →
 * report; the created fixtures surface on GET /api/fixtures; re-upload skips.
 */
#[Group('integration')]
final class ImportFixturesApiTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testUploadImportsThenReimportSkips(): void
    {
        [$token, $clubName, $teamId] = $this->registerWithTeam();

        $file = $this->xlsx([
            ['D2 Poule A', 'A9001', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', strtoupper($clubName) . ' - 1', '10/10/2026', '', ''],
        ]);

        $this->client->request('POST', '/api/teams/' . $teamId . '/fixtures/import', [], [
            'file' => new UploadedFile($file, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $report = $this->responseData();
        self::assertSame(2, $report['created']);
        self::assertSame(0, $report['skipped']);
        self::assertSame([], $report['errors']);

        // The fixtures surface on the collection, external ref exposed.
        $this->client->request('GET', '/api/fixtures', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $members = $this->responseData()['member'] ?? [];
        self::assertCount(2, $members);
        $refs = array_map(static fn (array $m): string => $m['externalRef'], $members);
        sort($refs);
        self::assertSame(['A9001', 'A9002'], $refs);

        // Re-upload the same file → nothing new.
        $file2 = $this->xlsx([
            ['D2 Poule A', 'A9001', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', strtoupper($clubName) . ' - 1', '10/10/2026', '', ''],
        ]);
        $this->client->request('POST', '/api/teams/' . $teamId . '/fixtures/import', [], [
            'file' => new UploadedFile($file2, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $second = $this->responseData();
        self::assertSame(0, $second['created']);
        self::assertSame(2, $second['skipped']);
    }

    public function testImportRefusedWhileSocleNotValidated(): void
    {
        // SocleGuard path on THIS controller: without a validated main plan the
        // import must 409 — the other tests pre-stamp the socle, so this is the
        // only request keeping that branch covered.
        [$token, $clubName, $teamId] = $this->registerWithTeam(validateSocle: false);

        $file = $this->xlsx([
            ['D2 Poule A', 'A9100', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
        ]);
        $this->client->request('POST', '/api/teams/' . $teamId . '/fixtures/import', [], [
            'file' => new UploadedFile($file, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testNonXlsxUploadIsRejected(): void
    {
        [$token, , $teamId] = $this->registerWithTeam();

        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.csv';
        file_put_contents($path, 'not;an;xlsx');
        $this->tempFiles[] = $path;

        $this->client->request('POST', '/api/teams/' . $teamId . '/fixtures/import', [], [
            'file' => new UploadedFile($path, 'fbi.csv', 'text/csv', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Register a club (its name is the HOME/AWAY needle) + create a team in the
     * register-seeded season.
     *
     * @return array{0: string, 1: string, 2: string} [token, clubName, teamId]
     */
    private function registerWithTeam(bool $validateSocle = true): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'fbi' . substr(md5(uniqid('', true)), 0, 6);
        $clubName = 'BC ' . ucfirst($suffix);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Bi', 'ara' => strtoupper($suffix), 'club_name' => $clubName,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        $clubId = $me['club']['id'];

        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        // SocleGuard: fixture import is a match-module write, refused (409) until
        // the season's main plan is validated — stamp it like the real flow would
        // (opt-out for the test covering the 409 branch itself).
        if ($validateSocle) {
            $season->setSocleValidatedAt(new DateTimeImmutable);
        }

        $sport = $this->em->getRepository(\App\Entity\Sport::class)->findOneBy(['isActive' => true]);
        self::assertNotNull($sport, 'register seeds the basketball sport');
        $category = new \App\Entity\SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return [$token, $clubName, $team->getId()];
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'Numéro', 'Équipe 1', 'Équipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
