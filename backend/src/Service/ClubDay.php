<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * « Quel jour est-on POUR CE CLUB ? » — foyer unique (P4-46, 2026-08-09).
 *
 * ⚑ La logique existait DEUX fois copiée quasi à l'identique (`TransitionReminderCommand::clubToday`,
 * `CoachWishDigestCommand` inline) — et DEUX consommateurs l'ignoraient :
 *
 *  · `PublicCoachWishController::isExpired` comparait la deadline au jour du SERVEUR. Pour un
 *    coach en Guadeloupe (UTC−4), le soir du dernier jour — 21h chez lui, déjà le lendemain à
 *    Paris — le lien rendait **410** alors que la règle promet « deadline INCLUSE, le jour même
 *    est ouvert ». Il perdait sa dernière soirée. C'est l'écart qui a motivé le foyer.
 *  · `SeasonResolver` faisait basculer le pivot du 15 juillet à minuit du serveur.
 *
 * **Pourquoi une date CIVILE et rien d'autre.** Tout le métier temporel de l'app est soit une
 * heure de MUR (créneaux : « 18:00 au gymnase » est local par nature, aucune conversion n'a de
 * sens), soit une date CIVILE (deadlines, périodes, pivot de saison). Le seul point où le fuseau
 * mord, c'est « quel jour est-on ? » — et la réponse dépend du club. Ce service ne fait QUE ça ;
 * un `ClubTimeService` généraliste (v3 §6.2) n'a pas d'objet.
 *
 * ⚠ Gardé par `ClubDayIsNotRebuiltTest` : réécrire ce calcul inline ailleurs fait rougir la CI —
 * c'est la réécriture qui recrée la dérive, pas la copie d'origine.
 */
final readonly class ClubDay
{
    /** Le fuseau FFBB par défaut — et le repli d'une timezone absente ou invalide. */
    private const string FALLBACK_TIMEZONE = 'Europe/Paris';

    public function __construct(private ClockInterface $clock) {}

    /** La date civile courante du club, à minuit — comparable en `Y-m-d`. */
    public function todayFor(Club $club): DateTimeImmutable
    {
        $timezone = self::FALLBACK_TIMEZONE;
        if ('' !== $club->getTimezone()) {
            try {
                new DateTimeZone($club->getTimezone());
                $timezone = $club->getTimezone();
            } catch (Throwable) {
                // Fuseau stocké invalide → repli FFBB, jamais d'explosion sur une donnée.
            }
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        return new DateTimeImmutable($now->setTimezone(new DateTimeZone($timezone))->format('Y-m-d'));
    }

    /** Raccourci pour les comparaisons date-à-date, la forme que tous les appelants utilisent. */
    public function todayYmdFor(Club $club): string
    {
        return $this->todayFor($club)->format('Y-m-d');
    }
}
