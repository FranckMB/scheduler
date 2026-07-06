import type { Category, Fixture, LeagueWindow, Team } from "../api";

/** Time-ish string ("18:00", "18:00:00", ISO) → minutes since midnight. */
export function timeToMinutes(time: string | null | undefined): number {
  const match = time?.match(/(\d{1,2}):(\d{2})/);
  if (null == match) {
    return 0;
  }
  return Number(match[1]) * 60 + Number(match[2]);
}

/** Y-m-d → ISO weekday 1..7 (Mon..Sun). */
export function isoWeekday(dateStr: string): number {
  const day = new Date(`${dateStr}T00:00:00`).getDay(); // 0=Sun..6=Sat
  return 0 === day ? 7 : day;
}

/** Normalize a label for a tolerant join (case/accents/spacing agnostic). */
function normalize(value: string): string {
  return value
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/\s+/g, "")
    .trim();
}

export interface EnvelopeResult {
  /** True only when the team maps to at least one league window (HARD guard active). */
  mapped: boolean;
  /** The windows that apply to the fixture's team (empty when unmapped). */
  windows: LeagueWindow[];
  /** Whether the fixture's DATE falls on an allowed day (only meaningful when mapped). */
  dayOk: boolean;
  /** Whether the kickoff falls inside an allowed window (only meaningful when mapped && dayOk). */
  timeOk: (kickoff: string) => boolean;
}

/**
 * Resolve the league-envelope windows that apply to a fixture's team and expose
 * day/time validators.
 *
 * The join is tolerant: it matches the team's (category, level, gender) against
 * the catalog labels, normalized. Because the club category/level labels are not
 * guaranteed to align 1:1 with the AURA catalog, `mapped` may be false — the UI
 * then shows the windows as an advisory reference and does NOT block placement
 * (degradation validated with the product). The server-side conflict radar stays
 * the hard source of truth.
 */
export function resolveEnvelope(
  fixture: Fixture,
  teams: Map<string, Team>,
  categories: Map<string, Category>,
  windows: LeagueWindow[],
): EnvelopeResult {
  const team = teams.get(fixture.teamId);
  const categoryName = team ? categories.get(team.sportCategoryId)?.name : undefined;

  const matched =
    undefined === team || undefined === categoryName
      ? []
      : windows.filter((w) => {
          const categoryMatch = normalize(w.category) === normalize(categoryName);
          // An unknown team level/gender must NOT match every window — that would
          // map the team to levels/genders it does not belong to and mis-flag the
          // envelope. Require the axis to be known and equal (a gender-null window
          // is catalog-wide and still applies).
          const levelMatch = null !== team.level && normalize(w.level) === normalize(team.level);
          const genderMatch = null === w.gender || (null !== team.gender && normalize(w.gender) === normalize(team.gender));
          return categoryMatch && levelMatch && genderMatch;
        });

  const day = isoWeekday(fixture.matchDate);
  const dayWindows = matched.filter((w) => w.dayOfWeek === day);

  return {
    mapped: matched.length > 0,
    windows: matched,
    dayOk: dayWindows.length > 0,
    timeOk: (kickoff: string) => {
      const min = timeToMinutes(kickoff);
      return dayWindows.some((w) => min >= timeToMinutes(w.kickoffMin) && min <= timeToMinutes(w.kickoffMax));
    },
  };
}

/**
 * Whether a placement (date already fixed, kickoff being chosen) sits inside the
 * envelope. Unmapped teams are never blocked (advisory only) → always in-envelope.
 */
export function isInEnvelope(envelope: EnvelopeResult, kickoff: string): boolean {
  if (!envelope.mapped) {
    return true;
  }
  return envelope.dayOk && envelope.timeOk(kickoff);
}
