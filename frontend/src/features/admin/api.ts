import ky from "ky";

export interface AdminIdentity {
  id: string;
  email: string;
}

interface PasswordResponse {
  mfaRequired: true;
}

interface TotpResponse {
  authenticated: true;
  csrfToken: string;
}

export interface AdminSessionResponse extends AdminIdentity {
  csrfToken: string;
}

export interface AdminOverviewResponse {
  clubs: {
    total: number;
    active7d: number;
    active30d: number;
    new7d: number;
    unsubscribed: number;
  };
  solver: {
    windowDays: number;
    generations: number;
    completed: number;
    failed: number;
    infeasible: number;
    infeasibleRate: number;
    p50WallTimeMs: number | null;
    p95WallTimeMs: number | null;
    daily: Array<{
      date: string;
      generations: number;
      infeasible: number;
      p50WallTimeMs: number | null;
      p95WallTimeMs: number | null;
    }>;
  };
  /** Stats d'usage (SA2-stats). Optionnel : un backend antérieur au lot (rollback,
   *  décalage de déploiement) sert l'ancien overview sans ce bloc — l'UI ne doit
   *  pas planter, elle affiche l'indisponibilité. */
  usage?: {
    plansByType: Array<{ type: string; total: number; validated: number }>;
    timeToFirstValidation: {
      season: { count: number; p50Minutes: number | null; p95Minutes: number | null };
      period: { count: number; p50Minutes: number | null; p95Minutes: number | null };
    };
    solverByPlanType: Array<{ planType: string; generations: number; p50WallTimeMs: number | null; p95WallTimeMs: number | null }>;
    clubSizes: Array<{ bucket: string; clubs: number; medianVenues: number | null }>;
  };
}

type HealthStatus = "up" | "down" | "unknown";

export interface AdminHealthResponse {
  status: "healthy" | "degraded";
  checkedAt: string;
  services: {
    database: { status: HealthStatus; latencyMs: number | null };
    redis: { status: HealthStatus; latencyMs: number | null };
    engine: { status: HealthStatus; latencyMs: number | null };
    mercure: { status: HealthStatus; latencyMs: number | null };
    worker: {
      status: HealthStatus;
      lastHeartbeatAt: string | null;
      ageSeconds: number | null;
    };
  };
  messenger: {
    status: "up" | "degraded" | "unknown";
    backlog: number | null;
    failed: number | null;
    retriesToday: number | null;
    backlogWarningThreshold: number;
  };
}

export interface AdminClub {
  id: string;
  name: string;
  slug: string;
  ffbbClubCode: string | null;
  planId: number | null;
  billingCycle: string | null;
  generationCountSeason: number;
  createdAt: string;
  lastActivityAt: string | null;
  unsubscribed: boolean;
  currentSeason: { id: string; name: string; status: string } | null;
  volumes: { teams: number; venues: number; coaches: number; constraints: number };
  solver: {
    generations: number;
    infeasible: number;
    infeasibleRate: number;
    p50WallTimeMs: number | null;
    p95WallTimeMs: number | null;
    latestStatus: string | null;
    latestAt: string | null;
  };
}

export interface AdminClubsResponse {
  items: AdminClub[];
  pagination: { page: number; limit: number; total: number; pages: number };
  metricsWindowDays: number;
}

export type AdminJobStatus = "running" | "succeeded" | "failed" | "interrupted";

export interface AdminJob {
  key: string;
  label: string;
  command: string;
  cadence: "every_10_minutes" | "daily" | "quarterly";
  manualTriggerAllowed: boolean;
  nextRunAt: string;
  latestRun: {
    id: string;
    status: AdminJobStatus;
    source: "scheduled" | "cli" | "superadmin";
    startedAt: string;
    finishedAt: string | null;
    durationMs: number | null;
    exitCode: number | null;
  } | null;
}

export interface AdminJobsResponse {
  items: AdminJob[];
}

export interface AdminJobRunResponse {
  key: string;
  status: "succeeded";
  exitCode: 0;
}

/** SA4 — action support sur un club, du catalogue FERMÉ (backend AdminActionCatalog). */
export interface AdminAction {
  key: string;
  label: string;
  description: string;
  /** Destructif → confirmation nominative (taper le nom du club). */
  dangerous: boolean;
}

export interface AdminActionsResponse {
  items: AdminAction[];
}

export interface AdminClubActionRunResponse {
  key: string;
  clubId: string;
  status: "succeeded";
  exitCode: 0;
}

/** Session-cookie client for /api/admin. It deliberately never reads the club JWT store. */
export const adminApi = ky.create({
  prefix: "/api/admin",
  credentials: "same-origin",
});

export function startAdminPassword(body: { email: string; password: string }): Promise<PasswordResponse> {
  return adminApi.post("auth/password", { json: body }).json();
}

export function completeAdminTotp(code: string): Promise<TotpResponse> {
  return adminApi.post("auth/totp", { json: { code } }).json();
}

export function getAdminSession(): Promise<AdminSessionResponse> {
  return adminApi.get("auth/me").json();
}

export function getAdminOverview(): Promise<AdminOverviewResponse> {
  return adminApi.get("overview").json();
}

export function getAdminHealth(): Promise<AdminHealthResponse> {
  return adminApi.get("health").json();
}

export function getAdminClubs(page: number, limit: number, query: string): Promise<AdminClubsResponse> {
  return adminApi.get("clubs", { searchParams: { page, limit, query } }).json();
}

export function getAdminJobs(): Promise<AdminJobsResponse> {
  return adminApi.get("jobs").json();
}

export function runAdminJob(key: string, csrfToken: string): Promise<AdminJobRunResponse> {
  return adminApi.post(`jobs/${encodeURIComponent(key)}/run`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function getAdminActions(): Promise<AdminActionsResponse> {
  return adminApi.get("actions").json();
}

export function runAdminClubAction(clubId: string, key: string, csrfToken: string): Promise<AdminClubActionRunResponse> {
  return adminApi.post(`clubs/${encodeURIComponent(clubId)}/actions/${encodeURIComponent(key)}`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function logoutAdmin(csrfToken: string): Promise<void> {
  return adminApi.post("auth/logout", { headers: { "X-CSRF-Token": csrfToken } }).then(() => undefined);
}
