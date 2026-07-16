import { visibleSeasonPlans } from "./versions";

const IN_FLIGHT = ["PENDING", "GENERATING"];

type LandingSchedule = { id: string; status: string; createdAt: string; calendarEntryId: string | null };

export function pickDefaultSchedule(schedules: LandingSchedule[]): string | null {
  const seasonPlans = visibleSeasonPlans(schedules);
  if (0 === seasonPlans.length) {
    return null;
  }
  const byRecent = [...seasonPlans].sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return (byRecent.find((s) => "COMPLETED" === s.status) ?? byRecent[0]).id;
}

export function pickLandingScheduleId(schedules: LandingSchedule[], chosenScheduleId: string | null): string | null {
  const base = schedules.find((s) => s.id === chosenScheduleId && null === s.calendarEntryId);

  return base && !IN_FLIGHT.includes(base.status) ? base.id : pickDefaultSchedule(schedules);
}
