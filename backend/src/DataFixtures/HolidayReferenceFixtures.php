<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Service\HolidaySeeder;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds the GLOBAL holiday reference tables (vacances scolaires + jours fériés)
 * as part of the data fixtures, so **every** load path — `doctrine:fixtures:load`
 * (smoke, CI, manual) as well as `make fixtures` — populates them. Before this,
 * the seed ran ONLY in the `make fixtures` Makefile target, so a fixture load by
 * any other means left a correctly-zoned club showing an empty holiday calendar.
 *
 * Global, non-tenant data (no club_id) → independent of BasketballInit (order
 * irrelevant). Delegates to the shared HolidaySeeder (same logic as the
 * `app:{school,public}-holidays:seed` commands).
 */
final class HolidayReferenceFixtures implements FixtureInterface, ORMFixtureInterface
{
    public function __construct(private readonly HolidaySeeder $seeder) {}

    public function load(ObjectManager $manager): void
    {
        $this->seeder->seedSchool();
        $this->seeder->seedPublic();
    }
}
