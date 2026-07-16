import { Activity, LogOut, ShieldCheck } from "lucide-react";
import { useState } from "react";
import { Outlet, useNavigate } from "react-router-dom";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";

import { logoutAdmin } from "./api";
import { useAdminStore } from "./store";

export function AdminShell() {
  const navigate = useNavigate();
  const identity = useAdminStore((state) => state.identity);
  const csrfToken = useAdminStore((state) => state.csrfToken);
  const clear = useAdminStore((state) => state.clear);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function logout() {
    if (!csrfToken) {
      clear();
      navigate("/admin/login", { replace: true });
      return;
    }
    setPending(true);
    setError(null);
    try {
      await logoutAdmin(csrfToken);
      clear();
      navigate("/admin/login", { replace: true });
    } catch (err) {
      setError(await apiErrorMessage(err));
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <header className="border-b border-white/10 bg-slate-950/90">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-lg bg-cyan-300/10 text-cyan-200"><ShieldCheck className="size-5" aria-hidden="true" /></div>
            <div><p className="font-semibold text-white">Console superadmin</p><p className="text-xs text-slate-500">Surface d’exploitation transverse</p></div>
          </div>
          <div className="flex items-center gap-4">
            <span className="hidden text-sm text-slate-400 sm:inline">{identity?.email}</span>
            <Button variant="ghost" size="sm" className="text-slate-300 hover:bg-white/10 hover:text-white" onClick={logout} disabled={pending}>
              {pending ? <Spinner className="size-4" /> : <LogOut className="size-4" aria-hidden="true" />}
              Sortir
            </Button>
          </div>
        </div>
      </header>
      {error ? <div className="border-b border-red-300/20 bg-red-400/10 px-6 py-3 text-center text-sm text-red-200" role="alert">{error}</div> : null}
      <main className="mx-auto max-w-7xl px-6 py-10"><Outlet /></main>
    </div>
  );
}

export function AdminHomePage() {
  return (
    <div className="space-y-10">
      <section className="max-w-2xl">
        <p className="mb-3 flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-cyan-300"><Activity className="size-4" aria-hidden="true" /> SA0 opérationnel</p>
        <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Vue d’ensemble de la plateforme</h1>
        <p className="mt-4 text-base leading-7 text-slate-400">Le socle d’accès est prêt. Les métriques, la supervision des jobs et les opérations globales seront ajoutées dans les prochains lots.</p>
      </section>
      <section className="grid gap-4 md:grid-cols-3" aria-label="État de la console">
        <StatusCard label="Authentification" value="Protégée" detail="Mot de passe + TOTP" />
        <StatusCard label="Session" value="Isolée" detail="Cookie admin, sans JWT club" />
        <StatusCard label="Audit" value="Actif" detail="Écriture fail-closed" />
      </section>
      <section className="rounded-2xl border border-dashed border-white/15 bg-white/[0.03] p-8">
        <p className="text-sm font-medium text-slate-200">Prochain jalon : métriques SA1</p>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Cette zone accueillera les indicateurs santé, usage et génération lorsque la persistance des métriques sera livrée.</p>
      </section>
    </div>
  );
}

function StatusCard({ label, value, detail }: { label: string; value: string; detail: string }) {
  return <article className="rounded-2xl border border-white/10 bg-white/[0.05] p-5"><p className="text-sm text-slate-400">{label}</p><p className="mt-3 text-2xl font-semibold text-white">{value}</p><p className="mt-1 text-xs text-slate-500">{detail}</p></article>;
}
