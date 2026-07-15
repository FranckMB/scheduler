const seededPeriods = new Set<string>();

export const periodSeedWasClaimed = (calendarEntryId: string): boolean => seededPeriods.has(calendarEntryId);
export const claimPeriodSeed = (calendarEntryId: string): void => {
  seededPeriods.add(calendarEntryId);
};
export const resetPeriodSeed = (): void => {
  seededPeriods.clear();
};
