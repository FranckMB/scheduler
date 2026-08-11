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
 * Fixtures de dev : le club BCCL réaliste (état terrain) PUIS le club de
 * DÉMONSTRATION permanent — les deux profils du même {@see BcclSeeder}. Depuis
 * P2-4 PR 2bis la logique vit dans le seeder (src/Seed, disponible en PROD pour
 * le club de démonstration — `doctrine-fixtures` est en require-dev, cette classe
 * n'existe pas dans l'image de prod) ; ce fichier n'est plus que l'habillage
 * fixture qui l'appelle. La démo entre dans `make fixtures` parce que le purge
 * des fixtures la détruisait sans jamais la recréer (décision fondateur).
 */
final class BasketballInit implements FixtureInterface, ORMFixtureInterface
{
    // Mot de passe du gestionnaire de démo en DEV uniquement : les fixtures
    // (`doctrine-fixtures`, require-dev) ne tournent JAMAIS en prod — où la démo
    // naît par `app:demo:seed-bccl --password=…`. Ce littéral n'existe pas hors dev.
    private const string DEMO_DEV_PASSWORD = 'DemoBcclDev!2026';

    public function __construct(private readonly BcclSeeder $seeder) {}

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('Expected EntityManagerInterface');
        }

        $this->seeder->run($manager, BcclSeedProfile::dev());
        $this->seeder->run($manager, BcclSeedProfile::demo(self::DEMO_DEV_PASSWORD));
    }
}
