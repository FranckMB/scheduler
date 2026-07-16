import {
  Activity,
  AlertTriangle,
  Building2,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Cpu,
  Database,
  History,
  MapPin,
  Radio,
  RefreshCw,
  RotateCw,
  Search,
  Server,
  Users,
  Workflow,
  Zap,
} from "lucide-react";
import { type FormEvent, type ReactNode, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { AdminClub, AdminHealthResponse, AdminJob, AdminJobStatus, AdminJobsResponse, AdminOverviewResponse } from "./api";
import { useAdminClubs, useAdminHealth, useAdminJobs, useAdminOverview, useRunAdminJob } from "./queries";

const CLUBS_PER_PAGE = 25;

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
const shortDateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short" });

export function AdminDashboardPage() {
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState("");
  const [queryDraft, setQueryDraft] = useState("");
  const overview = useAdminOverview();
  const health = useAdminHealth();
  const jobs = useAdminJobs();
  const clubs = useAdminClubs(page, CLUBS_PER_PAGE, query);
  const refreshing = overview.isFetching || health.isFetching || jobs.isFetching || clubs.isFetching;

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPage(1);
    setQuery(queryDraft.trim());
  }

  function refreshAll() {
    void Promise.all([overview.refetch(), health.refetch(), jobs.refetch(), clubs.refetch()]);
  }

  return (
    <div className="space-y-8">
      <section className="flex flex-col justify-between gap-5 border-b border-white/10 pb-8 lg:flex-row lg:items-end">
        <div className="max-w-3xl">
          <p className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">
            <Activity className="size-4" aria-hidden="true" /> Supervision temps réel
          </p>
          <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">État de la plateforme</h1>
          <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-400">
            Santé technique, activité du parc et comportement du solveur réunis dans une vue en lecture seule.
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          className="self-start border-white/15 text-slate-200 hover:bg-white/10 lg:self-auto"
          disabled={refreshing}
          onClick={refreshAll}
        >
          {refreshing ? <Spinner className="size-4 text-slate-300" /> : <RefreshCw aria-hidden="true" />}
          Actualiser
        </Button>
      </section>

      <OverviewSection data={overview.data} loading={overview.isPending} error={overview.isError} retry={() => void overview.refetch()} />
      <HealthSection data={health.data} loading={health.isPending} error={health.isError} retry={() => void health.refetch()} />
      <JobsSection data={jobs.data} loading={jobs.isPending} error={jobs.isError} retry={() => void jobs.refetch()} />

      <section aria-labelledby="clubs-heading" className="space-y-4">
        <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Parc client</p>
            <h2 id="clubs-heading" className="mt-2 text-xl font-semibold text-white">Comptes clubs</h2>
          </div>
          <form className="flex w-full gap-2 md:max-w-md" role="search" onSubmit={submitSearch}>
            <label className="sr-only" htmlFor="club-search">Rechercher un club</label>
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
              <input
                id="club-search"
                type="search"
                value={queryDraft}
                maxLength={100}
                onChange={(event) => setQueryDraft(event.target.value)}
                placeholder="Nom, slug ou code FFBB"
                className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] pl-10 pr-3 text-sm text-white outline-none placeholder:text-slate-600 focus:border-cyan-300/70 focus:ring-2 focus:ring-cyan-300/20"
              />
            </div>
            <Button type="submit" className="bg-cyan-300 text-slate-950 hover:bg-cyan-200">Rechercher</Button>
          </form>
        </div>

        {clubs.isPending ? <PanelLoading label="Chargement des clubs" /> : null}
        {clubs.isError ? <PanelError label="Les comptes clubs sont indisponibles." retry={() => void clubs.refetch()} /> : null}
        {clubs.data ? (
          <ClubsTable
            clubs={clubs.data.items}
            page={clubs.data.pagination.page}
            pages={clubs.data.pagination.pages}
            total={clubs.data.pagination.total}
            query={query}
            loading={clubs.isFetching}
            onPageChange={setPage}
          />
        ) : null}
      </section>
    </div>
  );
}

function OverviewSection({ data, loading, error, retry }: DataSectionProps<AdminOverviewResponse>) {
  if (loading) return <PanelLoading label="Chargement de l’activité" />;
  if (error || !data) return <PanelError label="Les indicateurs d’activité sont indisponibles." retry={retry} />;

  const metrics = [
    { label: "Clubs", value: data.clubs.total, detail: `${integerFormatter.format(data.clubs.new7d)} nouveaux sur 7 j` },
    { label: "Actifs sur 7 j", value: data.clubs.active7d, detail: `${integerFormatter.format(data.clubs.active30d)} actifs sur 30 j` },
    { label: "Générations", value: data.solver.generations, detail: `Fenêtre de ${data.solver.windowDays} jours` },
    { label: "Taux infaisable", value: formatRate(data.solver.infeasibleRate), detail: `${integerFormatter.format(data.solver.infeasible)} générations` },
  ];

  return (
    <section aria-labelledby="activity-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Activité globale</p>
        <h2 id="activity-heading" className="mt-2 text-xl font-semibold text-white">Parc et solveur</h2>
      </div>
      <div className="grid gap-px overflow-hidden rounded-xl border border-white/10 bg-white/10 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map((metric) => <Metric key={metric.label} {...metric} />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(18rem,1fr)]">
        <SolverChart solver={data.solver} />
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">Performance du solveur</p>
          <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
            <SmallMetric label="Terminées" value={data.solver.completed} />
            <SmallMetric label="Échecs" value={data.solver.failed} tone={data.solver.failed > 0 ? "danger" : undefined} />
            <SmallMetric label="Durée médiane" value={formatDuration(data.solver.p50WallTimeMs)} />
            <SmallMetric label="P95" value={formatDuration(data.solver.p95WallTimeMs)} />
          </dl>
          <div className="mt-6 border-t border-white/10 pt-4 text-xs text-slate-500">
            {integerFormatter.format(data.clubs.unsubscribed)} compte{data.clubs.unsubscribed > 1 ? "s" : ""} désabonné{data.clubs.unsubscribed > 1 ? "s" : ""}
          </div>
        </article>
      </div>
    </section>
  );
}

function SolverChart({ solver }: { solver: AdminOverviewResponse["solver"] }) {
  const max = Math.max(...solver.daily.map((day) => day.generations), 1);

  return (
    <figure className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-medium text-white">Volume quotidien</p>
          <p className="mt-1 text-xs text-slate-500">Générations sur les {solver.windowDays} derniers jours</p>
        </div>
        <span className="flex items-center gap-2 text-xs text-slate-500"><span className="size-2 bg-cyan-300" /> Générations</span>
      </div>
      {solver.daily.length > 0 ? (
        <>
          <div className="mt-6 flex h-36 items-end gap-1" aria-hidden="true">
            {solver.daily.map((day) => (
              <div key={day.date} className="group relative flex h-full min-w-0 flex-1 items-end">
                <div
                  className="w-full min-w-1 bg-cyan-300/60 transition-colors group-hover:bg-cyan-200"
                  style={{ height: `${Math.max((day.generations / max) * 100, day.generations > 0 ? 4 : 1)}%` }}
                />
              </div>
            ))}
          </div>
          <div className="mt-2 flex justify-between text-[11px] text-slate-600">
            <span>{formatShortDate(solver.daily[0]?.date)}</span>
            <span>{formatShortDate(solver.daily.at(-1)?.date)}</span>
          </div>
          <figcaption className="sr-only">
            {solver.daily.map((day) => `${day.date} : ${day.generations} générations`).join(" ; ")}
          </figcaption>
        </>
      ) : <p className="mt-10 text-sm text-slate-500">Aucune génération sur cette période.</p>}
    </figure>
  );
}

function HealthSection({ data, loading, error, retry }: DataSectionProps<AdminHealthResponse>) {
  if (loading) return <PanelLoading label="Chargement de la santé technique" />;
  if (error || !data) return <PanelError label="La santé technique est indisponible." retry={retry} />;

  const services = [
    { key: "database", label: "Base de données", icon: Database, ...data.services.database },
    { key: "redis", label: "Redis", icon: Zap, ...data.services.redis },
    { key: "engine", label: "Moteur", icon: Cpu, ...data.services.engine },
    { key: "mercure", label: "Mercure", icon: Radio, ...data.services.mercure },
  ];

  return (
    <section aria-labelledby="health-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Infrastructure</p>
          <h2 id="health-heading" className="mt-2 text-xl font-semibold text-white">Santé technique</h2>
        </div>
        <p className="text-xs text-slate-500">Vérifié le {formatDateTime(data.checkedAt)}</p>
      </div>
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,1fr)]">
        <article className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.04]">
          <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div className="flex items-center gap-3"><Server className="size-4 text-slate-500" aria-hidden="true" /><span className="text-sm font-medium text-white">Dépendances</span></div>
            <StatusChip status={data.status === "healthy" ? "up" : "degraded"} />
          </div>
          <ul className="grid sm:grid-cols-2">
            {services.map(({ key, label, icon: Icon, status, latencyMs }) => (
              <li key={key} className="flex items-center justify-between gap-4 border-b border-white/10 px-5 py-4 last:border-b-0 sm:odd:border-r sm:[&:nth-last-child(-n+2)]:border-b-0">
                <div className="flex items-center gap-3"><Icon className="size-4 text-slate-500" aria-hidden="true" /><span className="text-sm text-slate-300">{label}</span></div>
                <div className="text-right"><StatusChip status={status} /><p className="mt-1 text-[11px] text-slate-600">{latencyMs === null ? "—" : `${latencyMs} ms`}</p></div>
              </li>
            ))}
          </ul>
        </article>

        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-3"><Workflow className="size-4 text-slate-500" aria-hidden="true" /><span className="text-sm font-medium text-white">Traitements asynchrones</span></div>
            <StatusChip status={data.messenger.status} />
          </div>
          <dl className="mt-5 grid grid-cols-3 gap-3 border-b border-white/10 pb-5">
            <SmallMetric label="En attente" value={nullableInteger(data.messenger.backlog)} />
            <SmallMetric label="Échecs" value={nullableInteger(data.messenger.failed)} tone={(data.messenger.failed ?? 0) > 0 ? "danger" : undefined} />
            <SmallMetric label="Retries" value={nullableInteger(data.messenger.retriesToday)} />
          </dl>
          <div className="mt-5 flex items-start justify-between gap-4">
            <div><p className="text-xs text-slate-500">Worker</p><p className="mt-1 text-sm text-slate-300">{formatHeartbeat(data.services.worker)}</p></div>
            <StatusChip status={data.services.worker.status} />
          </div>
        </article>
      </div>
    </section>
  );
}

function JobsSection({ data, loading, error, retry }: DataSectionProps<AdminJobsResponse>) {
  const [jobToRun, setJobToRun] = useState<AdminJob | null>(null);
  const runJob = useRunAdminJob();

  function confirmRun() {
    if (!jobToRun) return;

    const job = jobToRun;
    setJobToRun(null);
    runJob.mutate(job.key, {
      onSuccess: () => toast.success(`${job.label} terminé.`),
      onError: () => toast.error(`Impossible d’exécuter « ${job.label} ».`),
    });
  }

  if (loading) return <PanelLoading label="Chargement des jobs opérationnels" />;
  if (error || !data) return <PanelError label="Les jobs opérationnels sont indisponibles." retry={retry} />;

  return (
    <section aria-labelledby="jobs-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Exploitation</p>
          <h2 id="jobs-heading" className="mt-2 text-xl font-semibold text-white">Jobs opérationnels</h2>
        </div>
        <p className="text-xs text-slate-500">Cadence, prochain passage et dernière exécution connue</p>
      </div>
      {data.items.length === 0 ? (
        <div className="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center text-sm text-slate-500">Aucun job opérationnel configuré.</div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1180px] text-left text-sm">
              <caption className="sr-only">État des jobs opérationnels allowlistés</caption>
              <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-slate-500">
                <tr><th className="px-5 py-4 font-medium">Job</th><th className="px-4 py-4 font-medium">Cadence</th><th className="px-4 py-4 font-medium">Prochain passage</th><th className="px-4 py-4 font-medium">Dernière exécution</th><th className="px-4 py-4 font-medium">Durée</th><th className="px-4 py-4 font-medium">Résultat</th><th className="px-4 py-4 font-medium">Action</th></tr>
              </thead>
              <tbody className="divide-y divide-white/10">
                {data.items.map((job) => <JobRow key={job.key} job={job} running={runJob.isPending && runJob.variables === job.key} onRun={() => setJobToRun(job)} />)}
              </tbody>
            </table>
          </div>
        </div>
      )}
      <ConfirmDialog
        open={jobToRun !== null}
        title="Relancer cet import ?"
        description={jobToRun ? `« ${jobToRun.label} » va interroger la source officielle et mettre à jour la référence globale. L’opération est idempotente.` : undefined}
        confirmLabel="Relancer l’import"
        destructive={false}
        onConfirm={confirmRun}
        onCancel={() => setJobToRun(null)}
      />
    </section>
  );
}

function JobRow({ job, running, onRun }: { job: AdminJob; running: boolean; onRun: () => void }) {
  return (
    <tr className="align-top text-slate-300 hover:bg-white/[0.025]">
      <td className="px-5 py-5"><div className="flex items-start gap-3"><div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-white/[0.06] text-slate-500"><History className="size-4" aria-hidden="true" /></div><div><p className="font-medium text-white">{job.label}</p><p className="mt-1 font-mono text-[11px] text-slate-600">{job.command}</p></div></div></td>
      <td className="px-4 py-5">{formatCadence(job.cadence)}</td>
      <td className="px-4 py-5"><NextRun value={job.nextRunAt} /></td>
      <td className="px-4 py-5">{job.latestRun ? <><p>{formatDateTime(job.latestRun.startedAt)}</p><p className="mt-1 text-xs text-slate-600">{formatJobSource(job.latestRun.source)}</p></> : <span className="text-slate-500">Jamais exécuté</span>}</td>
      <td className="px-4 py-5 tabular-nums">{job.latestRun ? formatDuration(job.latestRun.durationMs) : "—"}</td>
      <td className="px-4 py-5"><JobStatus status={job.latestRun?.status ?? null} />{job.latestRun?.exitCode !== null && job.latestRun?.exitCode !== undefined ? <p className="mt-1 text-[11px] text-slate-600">Code {job.latestRun.exitCode}</p> : null}</td>
      <td className="px-4 py-5">{job.manualTriggerAllowed ? <Button type="button" size="sm" variant="outline" className="border-white/15 text-slate-200 hover:bg-white/10" disabled={running} onClick={onRun}>{running ? <Spinner className="size-3.5" /> : <RotateCw className="size-3.5" aria-hidden="true" />} {running ? "Exécution…" : "Relancer"}</Button> : <span className="text-xs text-slate-600">Supervision seule</span>}</td>
    </tr>
  );
}

function NextRun({ value }: { value: string }) {
  return <p className="text-slate-300">{formatDateTime(value)}</p>;
}

function JobStatus({ status }: { status: AdminJobStatus | null }) {
  const labels: Record<AdminJobStatus, string> = { running: "En cours", succeeded: "Réussi", failed: "Échec", interrupted: "Interrompu" };
  if (status === null) return <span className="text-xs font-medium text-slate-500">Sans historique</span>;

  const successful = status === "succeeded";
  const running = status === "running";
  const Icon = successful ? CheckCircle2 : status === "failed" ? AlertTriangle : History;
  return <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", successful ? "text-emerald-300" : running ? "text-cyan-300" : status === "failed" ? "text-amber-300" : "text-slate-400")}><Icon className="size-3.5" aria-hidden="true" />{labels[status]}</span>;
}

function ClubsTable({ clubs, page, pages, total, query, loading, onPageChange }: {
  clubs: AdminClub[];
  page: number;
  pages: number;
  total: number;
  query: string;
  loading: boolean;
  onPageChange: (page: number) => void;
}) {
  if (clubs.length === 0) {
    return <div className="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center text-sm text-slate-500">{query ? `Aucun club ne correspond à « ${query} ».` : "Aucun club à afficher."}</div>;
  }

  return (
    <div className={cn("overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]", loading && "opacity-70")}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1040px] text-left text-sm">
          <caption className="sr-only">Liste des comptes clubs et de leurs métriques</caption>
          <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-slate-500">
            <tr><th className="px-5 py-4 font-medium">Club</th><th className="px-4 py-4 font-medium">Activité</th><th className="px-4 py-4 font-medium">Offre</th><th className="px-4 py-4 font-medium">Saison / volume</th><th className="px-4 py-4 font-medium">Solveur · 30 j</th></tr>
          </thead>
          <tbody className="divide-y divide-white/10">
            {clubs.map((club) => <ClubRow key={club.id} club={club} />)}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between gap-4 border-t border-white/10 px-5 py-4">
        <p className="text-xs text-slate-500">{integerFormatter.format(total)} compte{total > 1 ? "s" : ""} · page {page} sur {Math.max(pages, 1)}</p>
        <div className="flex gap-2">
          <Button type="button" size="sm" variant="ghost" className="text-slate-300 hover:bg-white/10" aria-label="Page précédente" disabled={page <= 1 || loading} onClick={() => onPageChange(page - 1)}><ChevronLeft aria-hidden="true" /></Button>
          <Button type="button" size="sm" variant="ghost" className="text-slate-300 hover:bg-white/10" aria-label="Page suivante" disabled={page >= pages || loading} onClick={() => onPageChange(page + 1)}><ChevronRight aria-hidden="true" /></Button>
        </div>
      </div>
    </div>
  );
}

function ClubRow({ club }: { club: AdminClub }) {
  return (
    <tr className="align-top text-slate-300 hover:bg-white/[0.025]">
      <td className="px-5 py-5"><div className="flex items-start gap-3"><div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-white/[0.06] text-slate-500"><Building2 className="size-4" aria-hidden="true" /></div><div><p className="font-medium text-white">{club.name}</p><p className="mt-1 text-xs text-slate-600">{club.ffbbClubCode ?? club.slug}</p>{club.unsubscribed ? <span className="mt-2 inline-block text-[11px] font-medium text-amber-300">Désabonné</span> : null}</div></div></td>
      <td className="px-4 py-5"><p>{club.lastActivityAt ? formatDate(club.lastActivityAt) : "Jamais"}</p><p className="mt-1 text-xs text-slate-600">Créé le {formatDate(club.createdAt)}</p></td>
      <td className="px-4 py-5"><p className="text-white">{club.planId === null ? "Découverte" : "Payant"}</p><p className="mt-1 text-xs text-slate-600">{club.generationCountSeason} génération{club.generationCountSeason > 1 ? "s" : ""}{club.billingCycle ? ` · ${club.billingCycle}` : ""}</p></td>
      <td className="px-4 py-5"><p>{club.currentSeason?.name ?? "Aucune saison"}</p><p className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600"><span className="flex items-center gap-1"><Users className="size-3" aria-hidden="true" />{club.volumes.teams} équipes · {club.volumes.coaches} coachs</span><span className="flex items-center gap-1"><MapPin className="size-3" aria-hidden="true" />{club.volumes.venues} salles</span><span>{club.volumes.constraints} contraintes</span></p></td>
      <td className="px-4 py-5"><p><span className="font-medium text-white">{club.solver.generations}</span> générations · {formatRate(club.solver.infeasibleRate)} inf.</p><p className="mt-1 text-xs text-slate-600">P50 {formatDuration(club.solver.p50WallTimeMs)} · P95 {formatDuration(club.solver.p95WallTimeMs)}</p>{club.solver.latestStatus ? <p className="mt-2 text-[11px] text-slate-500">Dernière : {club.solver.latestStatus}</p> : null}</td>
    </tr>
  );
}

function Metric({ label, value, detail }: { label: string; value: number | string; detail: string }) {
  return <article className="bg-slate-950 p-5"><p className="text-xs text-slate-500">{label}</p><p className="mt-3 text-2xl font-semibold tabular-nums text-white">{typeof value === "number" ? integerFormatter.format(value) : value}</p><p className="mt-1 text-xs text-slate-600">{detail}</p></article>;
}

function SmallMetric({ label, value, tone }: { label: string; value: number | string; tone?: "danger" }) {
  return <div><dt className="text-xs text-slate-500">{label}</dt><dd className={cn("mt-1 text-lg font-semibold tabular-nums text-white", tone === "danger" && "text-amber-300")}>{typeof value === "number" ? integerFormatter.format(value) : value}</dd></div>;
}

function StatusChip({ status }: { status: "up" | "down" | "unknown" | "degraded" }) {
  const labels = { up: "Opérationnel", down: "Indisponible", unknown: "Inconnu", degraded: "Dégradé" };
  const healthy = status === "up";
  const Icon = healthy ? CheckCircle2 : AlertTriangle;
  return <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", healthy ? "text-emerald-300" : status === "unknown" ? "text-slate-400" : "text-amber-300")}><Icon className="size-3.5" aria-hidden="true" />{labels[status]}</span>;
}

function PanelLoading({ label }: { label: string }) {
  return <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status"><Spinner className="text-cyan-300" /><span className="sr-only">{label}</span></div>;
}

function PanelError({ label, retry }: { label: string; retry: () => void }) {
  return <div className="flex flex-col items-start gap-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.05] p-5" role="alert"><p className="text-sm text-amber-100">{label}</p><Button type="button" size="sm" variant="outline" className="border-amber-300/20 text-amber-100 hover:bg-amber-300/10" onClick={retry}>Réessayer</Button></div>;
}

interface DataSectionProps<T> {
  data: T | undefined;
  loading: boolean;
  error: boolean;
  retry: () => void;
}

function formatRate(value: number): string {
  return new Intl.NumberFormat("fr-FR", { style: "percent", maximumFractionDigits: 1 }).format(value);
}

function formatDuration(milliseconds: number | null): string {
  if (milliseconds === null) return "—";
  if (milliseconds < 1000) return `${milliseconds} ms`;
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(milliseconds / 1000)} s`;
}

function formatDate(value: string): string {
  return dateFormatter.format(new Date(value));
}

function formatShortDate(value: string | undefined): string {
  return value ? shortDateFormatter.format(new Date(value)) : "";
}

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function nullableInteger(value: number | null): string {
  return value === null ? "—" : integerFormatter.format(value);
}

function formatHeartbeat(worker: AdminHealthResponse["services"]["worker"]): ReactNode {
  if (worker.ageSeconds !== null) return `Heartbeat il y a ${worker.ageSeconds} s`;
  if (worker.lastHeartbeatAt) return `Heartbeat ${formatDateTime(worker.lastHeartbeatAt)}`;
  return "Aucun heartbeat reçu";
}

function formatCadence(cadence: AdminJob["cadence"]): string {
  return {
    every_10_minutes: "Toutes les 10 minutes",
    daily: "Quotidien",
    quarterly: "Trimestriel",
  }[cadence];
}

function formatJobSource(source: NonNullable<AdminJob["latestRun"]>["source"]): string {
  return { scheduled: "Planifié", cli: "CLI", superadmin: "Superadmin" }[source];
}
