<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ADR-0002 : « le planning de la saison est arrêté » se dit désormais d'UNE façon —
 * le plan SEASON POINTE une version (`chosen_schedule_id`). Il n'y a plus de statut
 * `VALIDATED`, plus de `baselineScheduleId`, plus de `socleValidatedAt`.
 *
 * Les seeds qui posaient l'un de ces trois passent par ici. Attention : un seed
 * brut à l'EntityManager court-circuite le provisioning (qui, en vrai, a lieu à la
 * création de la saison puis à celle de la version) — d'où le ensureSeasonPlan +
 * linkSchedule avant le choose.
 */
trait ChoosesPlanVersionTrait
{
    /** Le plan de la version pointe cette version = « validée ». */
    private function choosePlanVersion(Schedule $schedule): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(SchedulePlanProvisioner::class);

        // C4 : linkSchedule ne résout plus le plan, il NUMÉROTE — la version doit déjà porter
        // son schedulePlanId. Si le seed ne l'a pas lié (cas courant : version de saison créée
        // à la main), on l'attache au plan SEASON de sa saison ; un overlay a été lié en amont.
        if (null === $schedule->getSchedulePlanId()) {
            $planId = $provisioner->ensureSeasonPlanId($schedule->getSeasonId());
            if (null !== $planId) {
                $schedule->setSchedulePlanId($planId);
                $em->flush();
            }
        }

        $provisioner->linkSchedule($schedule);
        // choose() rend false quand la version n'est rattachée à aucun plan (saison
        // absente, par ex. une seed qui invente un seasonId). Le laisser passer
        // rendrait un test « vert » sur un pointeur jamais posé.
        \PHPUnit\Framework\Assert::assertTrue(
            $provisioner->choose($schedule),
            'seed: la version n\'a pu être rattachée à aucun plan — la saison existe-t-elle ?',
        );
        $em->flush();
    }

    /**
     * « Le planning de la saison est arrêté » sans se soucier de QUELLE version —
     * ce que demandent les tests qui veulent seulement franchir le SocleGuard
     * (module matchs, état 3 du cockpit) et testent autre chose.
     */
    private function settleSeasonPlan(Season $season): Schedule
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('Planning')
            ->setStatus(ScheduleStatus::COMPLETED);
        $em->persist($schedule);
        $em->flush();

        $this->choosePlanVersion($schedule);

        return $schedule;
    }

    /**
     * Rattache une version seedée à son plan, comme la PRODUCTION le fait à la création
     * (POST /api/schedules → linkSchedule). Sans ça, une version seedée « à la main » a
     * `schedulePlanId` null — et depuis le lot C4 tout site de décision « est-ce le
     * socle ? » LÈVE sur ce troisième état (une version sans plan ne doit pas exister).
     *
     * - saison (`$calendarEntryId` null) : plan SEASON (ensureSeasonPlanId) ;
     * - overlay (`$calendarEntryId` fourni) : le plan de la période, provisionné (idempotent,
     *   comme le geste de création de la période).
     *
     * Depuis C4, le schedule ne porte plus `calendarEntryId` : on POSE `schedulePlanId` (ce
     * que fait la prod au POST), puis `linkSchedule` NUMÉROTE. L'entrée/la saison doivent
     * être persistées+flushées avant l'appel (résolution en SQL brut).
     */
    private function linkSeededSchedule(Schedule $schedule, ?string $calendarEntryId = null): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provisioner = static::getContainer()->get(SchedulePlanProvisioner::class);

        $planId = null === $calendarEntryId
            ? $provisioner->ensureSeasonPlanId($schedule->getSeasonId())
            : $provisioner->provisionPeriodPlan($calendarEntryId);
        $em->flush();
        if (null !== $planId) {
            $schedule->setSchedulePlanId($planId);
            $em->flush();
            $provisioner->linkSchedule($schedule);
        }
        $em->flush();
    }

    /**
     * Le plan SEASON vide qu'une vraie saison possède dès sa naissance — les 4
     * chemins de création le posent (register, POST /api/seasons, transition N+1,
     * reset). Un test qui persiste une Season à la main court-circuite ce geste
     * et obtiendrait une saison SANS plan, état qui n'existe pas en production.
     */
    private function provisionSeasonPlan(Season $season): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $em->flush();
    }

    /** La version pointée par le plan SEASON, ou null si le plan est encore un espace de travail. */
    private function chosenPlanVersion(Season $season): ?string
    {
        return static::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfSeasonPlan($season->getId());
    }
}
