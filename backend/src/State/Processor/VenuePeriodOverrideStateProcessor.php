<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenuePeriodOverrideResource;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\VenuePeriodOverride;
use App\Enum\VenuePeriodMode;
use App\Service\ManagementAccessGuard;
use App\Service\PlanVenueClosures;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\VenuePeriodGrid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
    use AssertsSchedulePlanExistsTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly VenuePeriodGrid $venuePeriodGrid,
        private readonly PlanVenueClosures $planVenueClosures,
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
        // L'existence du plan se contrôle AVANT la fermeture : le garde de fermeture
        // interroge la base avec l'id reçu, et un id vide sur une colonne `guid` remonterait
        // un 500 opaque au lieu du 422 attendu. La validation du DTO l'interdit aujourd'hui,
        // mais l'ordre rend l'invariant vrai par construction plutôt que par dépendance —
        // relevé en revue sécurité (2026-08-18).
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        $this->assertVenueNotFullyClosed($input->schedulePlanId, $input->venueId);

        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            /** @var VenuePeriodOverrideResource $output */
            // P4-34 — filet de la COURSE : le contrôle d'existence est un
            // check-then-insert ; entre lui et le flush, le plan peut disparaître
            // (suppression de période, reprise du socle). La FK remonterait alors
            // une violation que personne n'attrape → 500 opaque. Même 422 qu'un
            // plan déjà absent au contrôle.
            $output = $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
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
        $id = $uriVariables['id'] ?? null;
        $existing = \is_string($id) ? $this->entityManager->getRepository(VenuePeriodOverride::class)->find($id) : null;
        if (null !== $existing) {
            $this->assertVenueNotFullyClosed($existing->getSchedulePlanId(), $existing->getVenueId());
        }
        $before = $existing?->getMode();

        return $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId, $before): object {
            /** @var VenuePeriodOverrideResource $output */
            $output = $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));
            // Seul un CHANGEMENT de mode agit sur la grille. Re-enregistrer le mode déjà
            // en place doit être sans effet : rejouer la purge détruirait les créneaux que
            // le gestionnaire vient de saisir à la main (revue #8 — une action d'apparence
            // idempotente était destructrice à chaque répétition).
            if ($before?->value !== $output->mode) {
                $this->applyMode($output);
            }

            return $output;
        });
    }

    /**
     * DELETE = retour au défaut « hériter ». Ce que cela coûte dépend de l'état qu'on quitte :
     *
     *  - depuis VIERGE, la grille du gymnase a été VIDÉE : il n'y a rien à préserver et
     *    « hériter » veut dire reprendre le modèle de saison — on vide (ce qui a pu être
     *    ressaisi à la main) puis on RECOPIE. L'ordre importe : recopier avant de vider
     *    supprimerait la copie fraîche.
     *  - depuis DÉSACTIVÉ, la grille est INTACTE — c'est toute la promesse du mode
     *    (« réactiver rend la grille telle quelle ; désactiver ne doit pas coûter la
     *    saisie qu'on avait faite »). Or supprimer la ligne est la SEULE façon de
     *    réactiver un gymnase. Y rejouer la purge détruisait les réservations et les
     *    verrous HARD que le gestionnaire avait posés avant de désactiver, en lui
     *    rendant une grille d'apparence identique et sans un mot (revue #8, round 4).
     *    Réactiver ne touche donc à rien.
     *
     * Conséquence pour la PR-B : « reprendre la grille de saison » sur un gymnase
     * désactivé n'est pas ce DELETE — c'est un passage explicite par VIERGE, avec sa
     * confirmation, puisque c'est une destruction.
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $id = $uriVariables['id'] ?? null;
        $override = \is_string($id) ? $this->entityManager->getRepository(VenuePeriodOverride::class)->find($id) : null;
        $schedulePlanId = $override?->getSchedulePlanId();
        $venueId = $override?->getVenueId();
        // P2-37 D2 — DELETE = « hériter » = réactiver le gymnase. Un gymnase entièrement fermé
        // sur la fenêtre est INDISPONIBLE : supprimer l'override manuel posé AVANT la fermeture
        // ne doit pas offrir une réactivation de façade. On refuse comme POST/PUT.
        if (null !== $schedulePlanId && null !== $venueId) {
            $this->assertVenueNotFullyClosed($schedulePlanId, $venueId);
        }
        $wasBlank = VenuePeriodMode::BLANK === $override?->getMode();

        $this->entityManager->wrapInTransaction(function () use ($uriVariables, $clubId, $schedulePlanId, $venueId, $wasBlank): void {
            parent::processDelete($uriVariables, $clubId);
            if ($wasBlank && null !== $schedulePlanId && null !== $venueId) {
                $this->venuePeriodGrid->resetFromSeason($schedulePlanId, $venueId);
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
        if (!\in_array(null, [$input->schedulePlanId, $input->venueId, $this->entityManager->getRepository(VenuePeriodOverride::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'venueId' => $input->venueId])], true)) {
            throw new ValidationException('Ce gymnase a déjà un réglage pour cette période — modifiez-le.');
        }

        // Le mode est un réglage DE PÉRIODE. Le viser sur le plan de saison rendrait ses
        // actions destructrices : « vierge » viderait la grille du planning principal, et
        // le retour à « hériter » recopierait les créneaux de saison PAR-DESSUS eux-mêmes.
        // Le planning principal n'est JAMAIS modifié par une période (invariant n°1) —
        // on le rend impossible ici plutôt que d'en dépendre côté UI.
        if ($this->schedulePlanProvisioner->planIsSeason($input->schedulePlanId)) {
            throw new ValidationException('Un mode de gymnase se règle sur une période, pas sur le planning de la saison.');
        }

        $entity = new VenuePeriodOverride;
        if (null !== $input->schedulePlanId) {
            // P4-34 — l'ANCRE doit exister : sans ce contrôle, un `schedulePlanId`
            // inventé écrivait une ligne 201 rattachée à une période inexistante —
            // invisible à l'écran, jamais lue par une génération, impossible à
            // supprimer par l'UI. Même garde que `Reservation`/`VenueTrainingSlot` (P4-30).
            $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
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
     * P2-37 D2 — « non réversible » gardé côté serveur : un gymnase ENTIÈREMENT fermé sur la
     * fenêtre du plan est indisponible ; aucun mode (VIERGE/DÉSACTIVÉ) ni retour à « hériter »
     * (DELETE) n'a de sens dessus. L'indisponibilité totale est DÉRIVÉE (D1), jamais stockée :
     * une fermeture éditée après coup rouvre donc le geste toute seule. On refuse en 422 en
     * nommant la fermeture (titre + bornes) — même patron surfaçant le message que
     * `assertSchedulePlanExists` (`UnprocessableEntityHttpException`), déjà en vigueur dans ce
     * fichier via `AssertsSchedulePlanExistsTrait` ; le `ValidationException(string)` d'API
     * Platform, lui, ne remonte pas son message dans le corps.
     */
    private function assertVenueNotFullyClosed(?string $schedulePlanId, ?string $venueId): void
    {
        if (null === $schedulePlanId || null === $venueId) {
            return; // autres validations (ancre absente, plan de saison) traitées ailleurs
        }
        $closures = $this->planVenueClosures->forPlan($schedulePlanId);
        if (!isset($closures['fullyClosedVenueIds'][$venueId])) {
            return;
        }
        $label = PlanVenueClosures::describeForVenue($closures['summaries'], $venueId);

        throw new UnprocessableEntityHttpException(\sprintf('Ce gymnase est indisponible sur toute la période%s : son mode ne se règle plus tant que la fermeture tient. Ajustez ou levez la fermeture pour le rendre de nouveau réglable.', null !== $label ? ' — ' . $label : ''));
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
            $this->venuePeriodGrid->clear($output->schedulePlanId, $output->venueId);
        }
    }
}
