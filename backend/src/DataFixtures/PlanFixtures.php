<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Plan;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

final class PlanFixtures implements FixtureInterface, ORMFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected EntityManagerInterface');
        }

        $manager->getConnection()->executeStatement("SET LOCAL app.club_id = '11111111-1111-1111-1111-111111111111'");

        $existing = $manager->getRepository(Plan::class)->findOneBy(['name' => 'Découverte']);
        if ($existing instanceof Plan) {
            return;
        }

        $plans = [
            [
                'name' => 'Découverte',
                'maxTeams' => 5,
                'maxVenues' => 2,
                'maxGenerations' => 3,
                'monthlyPrice' => 0.0,
                'annualPrice' => 0.0,
                'features' => [
                    'pdf_export' => false,
                    'season_transition' => false,
                    'coach_player' => false,
                ],
            ],
            [
                'name' => 'Petit Club',
                'maxTeams' => 15,
                'maxVenues' => 4,
                'maxGenerations' => 10,
                'monthlyPrice' => 29.0,
                'annualPrice' => 290.0,
                'features' => [
                    'pdf_export' => true,
                    'season_transition' => false,
                    'coach_player' => true,
                ],
            ],
            [
                'name' => 'Club',
                'maxTeams' => 30,
                'maxVenues' => 6,
                'maxGenerations' => 25,
                'monthlyPrice' => 59.0,
                'annualPrice' => 590.0,
                'features' => [
                    'pdf_export' => true,
                    'season_transition' => true,
                    'coach_player' => true,
                ],
            ],
            [
                'name' => 'Grand Club',
                'maxTeams' => 50,
                'maxVenues' => 10,
                'maxGenerations' => 50,
                'monthlyPrice' => 99.0,
                'annualPrice' => 990.0,
                'features' => [
                    'pdf_export' => true,
                    'season_transition' => true,
                    'coach_player' => true,
                    'priority_support' => true,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $entity = $this->newEntity('App\Entity\Plan');
            $this->hydrate($entity, $plan);
            $manager->persist($entity);
        }

        $manager->flush();
    }

    private function newEntity(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Entity class %s does not exist yet (Phase 2).', $class));
        }

        return new $class();
    }

    /** @param array<string, mixed> $data */
    private function hydrate(object $entity, array $data): void
    {
        foreach ($data as $key => $value) {
            $setter = 'set'.ucfirst($key);
            if (method_exists($entity, $setter)) {
                $entity->$setter($value);
            }
        }
    }
}
