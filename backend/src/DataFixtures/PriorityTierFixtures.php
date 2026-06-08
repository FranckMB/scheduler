<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class PriorityTierFixtures implements FixtureInterface
{
    public function load(ObjectManager $manager): void
    {
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
            $entity = $this->newEntity('App\\Entity\\PriorityTier');
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

    private function hydrate(object $entity, array $data): void
    {
        foreach ($data as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($entity, $setter)) {
                $entity->$setter($value);
            }
        }
    }
}
