<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class SportFixtures implements FixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $entity = $this->newEntity('App\\Entity\\Sport');
        $this->hydrate($entity, [
            'name' => 'Basket',
            'slug' => 'basket',
            'icon' => 'basketball',
            'isActive' => true,
        ]);
        $manager->persist($entity);
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
