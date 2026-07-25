<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CoachWishCampaign;
use App\Service\CoachWishMailBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rendu des emails de collecte (feature #10, lot C3). Point sensible (revue #7) : sans base
 * front configurée, on OMET le lien plutôt que d'envoyer un chemin nu non cliquable.
 */
#[Group('phase1')]
final class CoachWishMailBuilderTest extends TestCase
{
    public function testCoachLinkEmailEmbedsTheAbsoluteLinkWhenBaseIsSet(): void
    {
        $builder = new CoachWishMailBuilder('https://app.example.test');
        $body = $builder->buildCoachLink('c@x.fr', 'Maxime', 'Club', $this->campaign(), 'Toussaint', str_repeat('a', 64))->getTextBody();

        self::assertStringContainsString('https://app.example.test/doleances/' . str_repeat('a', 64), (string) $body);
    }

    public function testCoachLinkEmailOmitsTheLinkWhenBaseIsEmpty(): void
    {
        $builder = new CoachWishMailBuilder('');
        $body = (string) $builder->buildCoachLink('c@x.fr', 'Maxime', 'Club', $this->campaign(), 'Toussaint', str_repeat('a', 64))->getTextBody();

        // Aucun chemin nu « /doleances/… » non cliquable ne doit fuiter dans le corps.
        self::assertStringNotContainsString('/doleances/', $body);
    }

    private function campaign(): CoachWishCampaign
    {
        return (new CoachWishCampaign)->setDeadline(new DateTimeImmutable('2027-06-30'));
    }
}
