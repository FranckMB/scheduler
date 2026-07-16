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

        $season = $em->getRepository(Season::class)->find($schedule->getSeasonId());
        if ($season instanceof Season) {
            $provisioner->ensureSeasonPlan($season);
            $em->flush();
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
