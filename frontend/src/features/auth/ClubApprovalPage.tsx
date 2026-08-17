import { useMutation, useQuery } from "@tanstack/react-query";
import { HTTPError } from "ky";
import { BadgeCheck, ShieldQuestion, ShieldX } from "lucide-react";
import { useParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";
import { PRODUCT_NAME } from "@/shared/lib/product";

import { AuthLayout } from "./AuthLayout";
import { decideClubApproval, getClubApproval } from "./api";

/**
 * P3-4 PR C — la page PUBLIQUE d'approbation, ouverte par le destinataire du
 * mail officiel FFBB du club (pas de compte : le token EST l'identité). Elle
 * nomme le demandeur et le club, et porte les deux gestes — Approuver crée
 * l'espace, Refuser clôt. 404 → lien invalide (ou déjà traité) ; 410 → expiré
 * (le support reste la voie de secours).
 */
export function ClubApprovalPage() {
  const { token = "" } = useParams();

  const info = useQuery({
    queryKey: ["club-approval", token],
    queryFn: () => getClubApproval(token),
    retry: false,
  });

  const decide = useMutation({
    mutationFn: (decision: "approve" | "refuse") => decideClubApproval(token, decision),
  });

  const httpStatus = info.error instanceof HTTPError ? info.error.response.status : null;

  if (decide.isSuccess) {
    const approved = "approved" === decide.data.status;
    return (
      <AuthLayout
        title={approved ? "Espace créé" : "Demande refusée"}
        description={approved ? "Le demandeur est prévenu par email — l'espace de votre club est prêt." : "Le demandeur est prévenu par email."}
      >
        <div className="flex flex-col items-center gap-4 py-2 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-muted">
            {approved ? <BadgeCheck className="size-6 text-success" /> : <ShieldX className="size-6 text-accent" />}
          </span>
          <p className="text-sm text-muted-foreground">Vous pouvez fermer cette page.</p>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Demande de création d'espace"
      description={`Un gestionnaire demande à créer l'espace ${PRODUCT_NAME} de votre club.`}
    >
      {info.isPending ? (
        <p className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
          <Spinner className="size-4" /> Chargement…
        </p>
      ) : info.isError ? (
        <div className="flex flex-col items-center gap-4 py-2 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-muted">
            <ShieldQuestion className="size-6 text-accent" />
          </span>
          <p className="text-sm text-muted-foreground">
            {410 === httpStatus
              ? `Cette demande a expiré (7 jours sans réponse). Le demandeur peut se rapprocher du support ${PRODUCT_NAME}.`
              : "Ce lien est invalide — ou la demande a déjà été traitée."}
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-5">
          <div className="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm">
            <p>
              <span className="font-medium">{info.data.requesterName}</span> demande à créer et gérer l'espace de{" "}
              <span className="font-medium">{info.data.clubName}</span> ({info.data.ara}).
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Sans réponse, la demande expire le {info.data.expiresAt}.</p>
          </div>
          <p className="text-sm text-muted-foreground">
            Si cette personne est bien un gestionnaire de votre club, approuvez : l'espace sera créé et elle en deviendra gestionnaire. Sinon, refusez — elle en sera informée.
          </p>
          {decide.isError ? (
            <p className="text-sm text-destructive">La décision n'a pas pu être enregistrée — réessayez.</p>
          ) : null}
          <div className="flex gap-3">
            <Button type="button" className="flex-1" disabled={decide.isPending} onClick={() => decide.mutate("approve")}>
              {decide.isPending ? <Spinner className="size-4" /> : null} Approuver
            </Button>
            <Button type="button" variant="outline" className="flex-1" disabled={decide.isPending} onClick={() => decide.mutate("refuse")}>
              Refuser
            </Button>
          </div>
        </div>
      )}
    </AuthLayout>
  );
}
