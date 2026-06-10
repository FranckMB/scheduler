<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthControllerTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdClubIds = [];

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->em) {
            foreach ($this->createdUserIds as $userId) {
                $user = $this->em->getRepository(User::class)->find($userId);
                if (null !== $user) {
                    $this->em->remove($user);
                }
            }

            foreach ($this->createdClubIds as $clubId) {
                $club = $this->em->getRepository(Club::class)->find($clubId);
                if (null !== $club) {
                    $clubUsers = $this->em->getRepository(ClubUser::class)->findBy(['clubId' => $clubId]);
                    foreach ($clubUsers as $cu) {
                        $this->em->remove($cu);
                    }

                    $seasons = $this->em->getRepository(Season::class)->findBy(['clubId' => $clubId]);
                    foreach ($seasons as $s) {
                        $this->em->remove($s);
                    }

                    $sportCategories = $this->em->getRepository(SportCategory::class)->findBy(['clubId' => $clubId]);
                    foreach ($sportCategories as $sc) {
                        $this->em->remove($sc);
                    }

                    $this->em->remove($club);
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        parent::tearDown();
    }

    private function generateUniqueAra(): string
    {
        return 'ARA'.strtoupper(substr(md5(uniqid()), 0, 10));
    }

    /** @group phase1 */
    public function testRegisterSuccess(): void
    {
        $unique = uniqid();
        $ara = $this->generateUniqueAra();

        $request = Request::create(
            '/api/register',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'test'.$unique.'@example.com',
                'password' => 'SecurePass123!',
                'ara' => $ara,
                'club_name' => 'Test Club '.$unique,
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(201, $response->getStatusCode(), 'Registration should return 201');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('token', $data, 'Response should contain JWT token');
        self::assertNotEmpty($data['token'], 'JWT token should not be empty');
        self::assertArrayHasKey('user', $data, 'Response should contain user data');
        self::assertArrayHasKey('id', $data['user'], 'User data should contain id');
        self::assertArrayHasKey('email', $data['user'], 'User data should contain email');

        $this->createdUserIds[] = $data['user']['id'];

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertNotNull($club, 'Club should be created with the given ARA');
        self::assertFalse($club->getOnboardingCompleted(), 'Club onboarding should not be completed');
        $this->createdClubIds[] = $club->getId();

        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        self::assertNotNull($season, 'Default season should be created');

        $sportCategory = $this->em->getRepository(SportCategory::class)->findOneBy(['clubId' => $club->getId()]);
        self::assertNotNull($sportCategory, 'Default sport category should be created');
        self::assertSame('basket', $sportCategory->getName(), 'Sport category should be named basket');

        $clubUser = $this->em->getRepository(ClubUser::class)->findOneBy([
            'clubId' => $club->getId(),
            'userId' => $data['user']['id'],
        ]);
        self::assertNotNull($clubUser, 'ClubUser membership should be created');
        self::assertSame('admin', $clubUser->getRole(), 'User should have admin role');
    }

    /** @group phase1 */
    public function testDuplicateAraReturns409(): void
    {
        $unique = uniqid();
        $ara = $this->generateUniqueAra();

        $request1 = Request::create(
            '/api/register',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'first'.$unique.'@example.com',
                'password' => 'SecurePass123!',
                'ara' => $ara,
                'club_name' => 'First Club '.$unique,
            ], JSON_THROW_ON_ERROR)
        );

        $response1 = self::$kernel->handle($request1);
        self::assertSame(201, $response1->getStatusCode(), 'First registration should succeed');
        $data1 = json_decode((string) $response1->getContent(), true);
        $this->createdUserIds[] = $data1['user']['id'];
        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        if (null !== $club) {
            $this->createdClubIds[] = $club->getId();
        }

        $request2 = Request::create(
            '/api/register',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'second'.$unique.'@example.com',
                'password' => 'SecurePass123!',
                'ara' => $ara,
                'club_name' => 'Second Club '.$unique,
            ], JSON_THROW_ON_ERROR)
        );

        $response2 = self::$kernel->handle($request2);
        self::assertSame(409, $response2->getStatusCode(), 'Duplicate ARA should return 409');

        $data2 = json_decode((string) $response2->getContent(), true);
        self::assertArrayHasKey('error', $data2, 'Error response should contain error message');
    }

    /** @group phase1 */
    public function testDuplicateEmailReturns409(): void
    {
        $unique = uniqid();
        $email = 'dupemail'.$unique.'@example.com';
        $ara1 = $this->generateUniqueAra();
        $ara2 = $this->generateUniqueAra();

        $request1 = Request::create(
            '/api/register',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $email,
                'password' => 'SecurePass123!',
                'ara' => $ara1,
                'club_name' => 'First Club '.$unique,
            ], JSON_THROW_ON_ERROR)
        );

        $response1 = self::$kernel->handle($request1);
        self::assertSame(201, $response1->getStatusCode(), 'First registration should succeed');
        $data1 = json_decode((string) $response1->getContent(), true);
        $this->createdUserIds[] = $data1['user']['id'];
        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara1]);
        if (null !== $club) {
            $this->createdClubIds[] = $club->getId();
        }

        $request2 = Request::create(
            '/api/register',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $email,
                'password' => 'SecurePass123!',
                'ara' => $ara2,
                'club_name' => 'Second Club '.$unique,
            ], JSON_THROW_ON_ERROR)
        );

        $response2 = self::$kernel->handle($request2);
        self::assertSame(409, $response2->getStatusCode(), 'Duplicate email should return 409');

        $data2 = json_decode((string) $response2->getContent(), true);
        self::assertArrayHasKey('error', $data2, 'Error response should contain error message');
    }
}
