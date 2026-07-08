<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Guards actions that require the season's MAIN plan to be validated first
 * (cockpit state machine, accueil-cockpit-temporel.md): matches and secondary
 * plans (period overlays) can only be created once the socle is stamped. Before
 * that the club is still finalising the main plan.
 *
 * 409 mirrors the archived-season / VALIDATED-lock idiom — the frontend toast
 * pipeline already surfaces it.
 */
final class SocleGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @throws ConflictHttpException when the season's socle is not validated yet */
    public function assertValidated(?string $seasonId): void
    {
        if (null === $seasonId) {
            return; // no resolved season → other guards handle it
        }
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
        if ($season instanceof Season && null === $season->getSocleValidatedAt()) {
            throw new ConflictHttpException('Validez le planning principal avant de créer un match ou un planning secondaire.');
        }
    }
}
