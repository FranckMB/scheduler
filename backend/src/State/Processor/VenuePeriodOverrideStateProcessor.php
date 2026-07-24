<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenuePeriodOverrideResource;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\VenuePeriodMode;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le MODE d'un gymnase pour une période (décision fondateur 2026-07-24).
 *
 * Une période POSSÈDE sa grille : ses créneaux ont été copiés depuis la saison à la
 * naissance du plan, et un gymnase n'a jamais deux jeux de créneaux dans une période.
 * Les trois options sont donc des ACTIONS sur cette grille, bornées à UN gymnase :
 *  - VIERGE (BLANK)   : on vide le gymnase, le gestionnaire ressaisit tout ;
 *  - HÉRITER (défaut) : on vide le gymnase puis on RECOPIE le modèle de saison — c'est
 *    le retour au défaut, donc la SUPPRESSION de la ligne (sparse : pas de ligne = hériter) ;
 *  - DÉSACTIVÉ        : le gymnase ne sert pas ; ses créneaux restent mais il sort du
 *    payload et des sélecteurs (« tout est lié au gymnase, son indisponibilité les impacte »).
 *
 * Vider passe par EntityCascadeDeleter::purgeChildrenOfSlot : supprimer un créneau
 * emporte ses réservations et les verrous qu'elles ont matérialisés. Un `remove()` nu
 * les laisserait orphelins (défaut relevé en revue de #285).
 *
 * @extends AbstractStateProcessor<VenuePeriodOverride, VenuePeriodOverrideInput, VenuePeriodOverrideResource>
 */
class VenuePeriodOverrideStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return VenuePeriodOverride::class;
    }

    /**
     * @param VenuePeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            /** @var VenuePeriodOverrideResource $output */
            $output = parent::processPost($input, $clubId, $seasonId);
            $this->applyMode($output);

            return $output;
        });
    }

    /**
     * @param array<string, mixed>     $uriVariables
     * @param VenuePeriodOverrideInput $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId): object {
            /** @var VenuePeriodOverrideResource $output */
            $output = parent::processPut($input, $uriVariables, $clubId, $seasonId);
            $this->applyMode($output);

            return $output;
        });
    }

    /**
     * DELETE = retour au défaut « hériter » : on vide le gymnase pour cette période puis
     * on RECOPIE le modèle de saison. L'ordre importe — recopier avant de vider
     * supprimerait la copie fraîche.
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $id = $uriVariables['id'] ?? null;
        $override = \is_string($id) ? $this->entityManager->getRepository(VenuePeriodOverride::class)->find($id) : null;
        $schedulePlanId = $override?->getSchedulePlanId();
        $venueId = $override?->getVenueId();

        $this->entityManager->wrapInTransaction(function () use ($uriVariables, $clubId, $schedulePlanId, $venueId): void {
            parent::processDelete($uriVariables, $clubId);
            if (null !== $schedulePlanId && null !== $venueId) {
                $this->clearVenueGrid($schedulePlanId, $venueId);
                $this->schedulePlanProvisioner->copySeasonalSlotsForVenue($schedulePlanId, $venueId);
            }
        });
    }

    /**
     * @param VenuePeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): VenuePeriodOverride
    {
        // Un seul réglage par (période, gymnase) — l'index unique remonterait sinon en 500
        // sur un double-submit ; on rend un 422 propre (l'édition passe par PUT).
        if (null !== $input->schedulePlanId && null !== $input->venueId
            && null !== $this->entityManager->getRepository(VenuePeriodOverride::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'venueId' => $input->venueId])) {
            throw new ValidationException('Ce gymnase a déjà un réglage pour cette période — modifiez-le.');
        }

        $entity = new VenuePeriodOverride;
        if (null !== $input->schedulePlanId) {
            $entity->setSchedulePlanId($input->schedulePlanId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->mode) {
            $entity->setMode(VenuePeriodMode::from($input->mode));
        }

        return $entity;
    }

    /**
     * @param VenuePeriodOverride      $entity
     * @param VenuePeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // schedulePlanId + venueId identifient la ligne — jamais remappés à l'édition.
        if (null !== $input->mode) {
            $entity->setMode(VenuePeriodMode::from($input->mode));
        }
    }

    /**
     * @param VenuePeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): VenuePeriodOverrideResource
    {
        return VenuePeriodOverrideResource::fromEntity($entity);
    }

    /**
     * L'ancre vient de la ressource PERSISTÉE, jamais du corps de la requête : au PUT,
     * schedulePlanId/venueId identifient la ligne et ne sont pas remappés — s'y fier
     * viderait la grille d'une AUTRE période (défaut relevé en revue de #285).
     */
    private function applyMode(VenuePeriodOverrideResource $output): void
    {
        // DÉSACTIVÉ CONSERVE LA GRILLE, il ne la SERT pas (décision fondateur
        // 2026-07-24) : le gestionnaire voit à l'écran ce qu'il a désactivé, et le
        // gymnase est simplement absent du payload envoyé à l'engine
        // (ScheduleConstraintBuilder::buildForOverlay). Réactiver rend la grille telle
        // quelle — désactiver ne doit pas coûter la saisie qu'on avait faite.
        // VIERGE, lui, vide bel et bien : c'est ce qu'on lui demande.
        if (VenuePeriodMode::BLANK->value === $output->mode) {
            $this->clearVenueGrid($output->schedulePlanId, $output->venueId);
        }
    }

    /** Vide la grille d'UN gymnase pour CETTE période — jamais les créneaux de saison. */
    private function clearVenueGrid(string $schedulePlanId, string $venueId): void
    {
        $slots = $this->entityManager->getRepository(VenueTrainingSlot::class)
            ->findBy(['schedulePlanId' => $schedulePlanId, 'venueId' => $venueId]);
        foreach ($slots as $slot) {
            // Cascade sanctionnée : emporte les réservations du créneau et les verrous
            // HARD qu'elles ont matérialisés.
            $this->cascadeDeleter?->purgeChildrenOfSlot($slot);
            $this->entityManager->remove($slot);
        }
        $this->entityManager->flush();
    }
}
