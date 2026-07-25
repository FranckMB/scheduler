import { useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminMessengerFailedResponse } from "../api";
import { useAdminMessengerFailed } from "../queries";

const FAILED_PER_PAGE = 50;

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateTimeFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" });

/**
 * Sous-onglet « Échecs async » du journal superadmin : liste paginée des
 * messages Messenger tombés sur le transport d'échec (retry épuisé).
 *
 * PII scope OUT #10 : le corps du message n'est JAMAIS affiché — le backend
 * filtre déjà la réponse (`AdminMessengerFailedController`), le frontend ne
 * tente pas de le récupérer ni de le rendre. Seuls l'id, la classe, la date
 * d'échec et le message d'erreur (stamp `ErrorDetailsStamp`) sont visibles.
 */
export function MessengerFailedSubtab() {
  const [page, setPage] = useState(1);
  const query = useAdminMessengerFailed(page, FAILED_PER_PAGE);

  if (query.isPending) {
    return (
      <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status">
        <Spinner className="text-cyan-300" />
        <span className="sr-only">Chargement des messages en échec</span>
      </div>
    );
  }

  if (query.isError) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.05] p-5" role="alert">
        <p className="text-sm text-amber-100">Les messages en échec sont indisponibles.</p>
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="border-amber-300/20 text-amber-100 hover:bg-amber-300/10"
          onClick={() => void query.refetch()}
        >
          Réessayer
        </Button>
      </div>
    );
  }

  const data: AdminMessengerFailedResponse | undefined = query.data;

  return (
    <section aria-labelledby="messenger-failed-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">File d’erreurs Messenger</p>
          <h2 id="messenger-failed-heading" className="mt-2 text-xl font-semibold text-white">Messages en échec</h2>
        </div>
        <p className="text-xs text-slate-500">Rafraîchi toutes les 60 s</p>
      </div>

      {data && data.items.length > 0 ? (
        <FailedTable
          data={data}
          loading={query.isFetching}
          onPageChange={setPage}
        />
      ) : (
        <div className="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center text-sm text-slate-500">
          Aucun message en échec — le système est sain
        </div>
      )}
    </section>
  );
}

function FailedTable({
  data,
  loading,
  onPageChange,
}: {
  data: AdminMessengerFailedResponse;
  loading: boolean;
  onPageChange: (page: number) => void;
}) {
  const { items, pagination } = data;
  const { page, pages, total } = pagination;

  return (
    <div className={cn("overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]", loading && "opacity-70")}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <caption className="sr-only">Messages Messenger en échec (retry épuisé)</caption>
          <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th className="px-5 py-4 font-medium">Classe</th>
              <th className="px-4 py-4 font-medium">Date d’échec</th>
              <th className="px-4 py-4 font-medium">Erreur</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-white/10">
            {items.map((item) => (
              <tr key={item.id} className="align-top text-slate-300 hover:bg-white/[0.025]">
                <td className="px-5 py-4">
                  <p className="font-mono text-[11px] text-slate-400">{item.class}</p>
                </td>
                <td className="px-4 py-4 tabular-nums">{formatDateTime(item.failedAt)}</td>
                <td className="px-4 py-4">
                  <p className="text-slate-300">{item.lastErrorMessage}</p>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between gap-4 border-t border-white/10 px-5 py-4">
        <p className="text-xs text-slate-500">
          {integerFormatter.format(total)} message{total > 1 ? "s" : ""} · page {page} sur {Math.max(pages, 1)}
        </p>
        <div className="flex gap-2">
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="text-slate-300 hover:bg-white/10"
            aria-label="Page précédente"
            disabled={page <= 1 || loading}
            onClick={() => onPageChange(page - 1)}
          >
            <ChevronLeft aria-hidden="true" />
          </Button>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="text-slate-300 hover:bg-white/10"
            aria-label="Page suivante"
            disabled={page >= pages || loading}
            onClick={() => onPageChange(page + 1)}
          >
            <ChevronRight aria-hidden="true" />
          </Button>
        </div>
      </div>
    </div>
  );
}

function formatDateTime(value: string): string {
  return dateTimeFormatter.format(new Date(value));
}