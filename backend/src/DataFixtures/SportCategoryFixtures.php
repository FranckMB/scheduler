<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class SportCategoryFixtures implements FixtureInterface, DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [SportFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $sport = $manager->getRepository('App\\Entity\\Sport')->findOneBy(['slug' => 'basket']);

        if ($sport === null) {
            throw new \RuntimeException('Sport "basket" must be loaded before SportCategoryFixtures.');
        }

        $categories = [
            ['name' => 'U9',  'ageMin' => 8,  'ageMax' => 9,  'sortOrder' => 1],
            ['name' => 'U11', 'ageMin' => 10, 'ageMax' => 11, 'sortOrder' => 2],
            ['name' => 'U13', 'ageMin' => 12, 'ageMax' => 13, 'sortOrder' => 3],
            ['name' => 'U15', 'ageMin' => 14, 'ageMax' => 15, 'sortOrder' => 4],
            ['name' => 'U18', 'ageMin' => 16, 'ageMax' => 18, 'sortOrder' => 5],
            ['name' => 'U21', 'ageMin' => 19, 'ageMax' => 21, 'sortOrder' => 6],
            ['name' => 'Seniors M', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 7],
            ['name' => 'Seniors F', 'ageMin' => 22, 'ageMax' => 99, 'sortOrder' => 8],
        ];

        foreach ($categories as $cat) {
            $entity = $this->newEntity('App\\Entity\\SportCategory');
            $this->hydrate($entity, array_merge($cat, [
                'sport' => $sport,
                'isCustom' => false,
            ]));
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
