<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\PriorityTier;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

final class PriorityTierFixtures implements FixtureInterface, ORMFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected EntityManagerInterface');
        }

        $manager->getConnection()->executeStatement("SET LOCAL app.club_id = '11111111-1111-1111-1111-111111111111'");

        $tiers = [
            [
                'id' => 1,
                'label' => 'S',
                'name' => 'Priorité S',
                'color' => '#D4AF37',
                'orToolsWeight' => 10000,
                'defaultMinSessions' => 3,
            ],
            [
                'id' => 2,
                'label' => 'A',
                'name' => 'Priorité A',
                'color' => '#9CA3AF',
                'orToolsWeight' => 1000,
                'defaultMinSessions' => 2,
            ],
            [
                'id' => 3,
                'label' => 'B',
                'name' => 'Priorité B',
                'color' => '#CD7F32',
                'orToolsWeight' => 100,
                'defaultMinSessions' => 2,
            ],
            [
                'id' => 4,
                'label' => 'C',
                'name' => 'Priorité C',
                'color' => '#3B82F6',
                'orToolsWeight' => 10,
                'defaultMinSessions' => 1,
            ],
            [
                'id' => 5,
                'label' => 'D',
                'name' => 'Priorité D',
                'color' => '#6B7280',
                'orToolsWeight' => 1,
                'defaultMinSessions' => 1,
            ],
        ];

        foreach ($tiers as $tier) {
            $existing = $manager->getRepository(PriorityTier::class)->find($tier['id']);
            if ($existing instanceof PriorityTier) {
                continue;
            }
            $entity = $this->newEntity('App\Entity\PriorityTier');
            $this->hydrate($entity, $tier);
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
