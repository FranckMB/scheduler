<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

/**
 * Fixtures de dev : le club BCCL réaliste (état terrain) + le FakeClub
 * d'onboarding — INCHANGÉS. Depuis P2-4 PR 2bis la logique vit dans
 * {@see BcclSeeder} (src/Seed, disponible en PROD pour le club de démonstration
 * — `doctrine-fixtures` est en require-dev, cette classe n'existe pas dans
 * l'image de prod) ; ce fichier n'est plus que l'habillage fixture qui
 * l'appelle avec le profil `dev()`.
 */
final class BasketballInit implements FixtureInterface, ORMFixtureInterface
{
    public function __construct(private readonly BcclSeeder $seeder) {}

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('Expected EntityManagerInterface');
        }

        $this->seeder->run($manager, BcclSeedProfile::dev());
    }
}
