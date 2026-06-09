<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Sport;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

final class SportFixtures implements FixtureInterface, ORMFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected EntityManagerInterface');
        }

        $manager->getConnection()->executeStatement("SET LOCAL app.club_id = '11111111-1111-1111-1111-111111111111'");

        $existing = $manager->getRepository(Sport::class)->findOneBy(['slug' => 'basket']);
        if ($existing instanceof Sport) {
            return;
        }

        $entity = $this->newEntity('App\Entity\Sport');
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
