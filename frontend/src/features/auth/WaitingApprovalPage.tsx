import { useQuery } from "@tanstack/react-query";
import { Clock, MailX, ShieldX } from "lucide-react";
import { useEffect } from "react";
import { useNavigate } from "react-router";

import { useAuthStore } from "@/shared/stores/authStore";
import { Button } from "@/shared/components/ui/button";

import { AuthLayout } from "./AuthLayout";
import { getMe } from "@/shared/session/api";
import { useLogout } from "./queries";

/**
 * P3-4 PR C — la salle d'attente dit désormais QUI doit approuver, et son ISSUE.
 * Deux attentes distinctes (jamais le même approbateur) :
 *  - `clubRequest` (création d'un club neuf) : c'est LE CLUB qui approuve, via
 *    son mail officiel FFBB — et le refus/l'expiration s'affichent ici, pas un
 *    silence éternel ;
 *  - membership pending (club existant) : c'est le gestionnaire en place.
 * La page poll /api/me (5 s) et entre toute seule dès l'approbation.
 */
export function WaitingApprovalPage() {
  const navigate = useNavigate();
  const logout = useLogout();
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);

  // Poll membership status so the screen advances automatically once approved.
  const { data } = useQuery({
    queryKey: ["me"],
    queryFn: getMe,
    enabled: isAuthenticated,
    refetchInterval: 5000,
    retry: false,
  });

  useEffect(() => {
    if (data?.membershipStatus === "active") {
      navigate("/", { replace: true });
    }
  }, [data?.membershipStatus, navigate]);

  const request = data?.clubRequest ?? null;
  // P1-1 : un accès DÉSACTIVÉ par un gestionnaire n'est pas une attente d'approbation —
  // dire « demande en attente » ici mentirait. Distinct, et réversible : la page entre
  // toute seule dès la réactivation (le poll bascule sur `active`).
  const deactivated = data?.membershipStatus === "deactivated";
  const state = deactivated
    ? ({ title: "Accès désactivé", description: "Votre accès au club a été suspendu.", icon: ShieldX } as const)
    : request === null
      ? ({ title: "Demande en attente", description: "Votre demande a bien été enregistrée.", icon: Clock } as const)
      : request.status === "refused"
        ? ({ title: "Demande refusée", description: `La création de l'espace ${request.clubName} a été refusée.`, icon: ShieldX } as const)
        : request.status === "expired"
          ? ({ title: "Demande expirée", description: `Le club n'a pas répondu dans les 7 jours.`, icon: MailX } as const)
          : ({ title: "En attente du club", description: `Votre demande de création de l'espace ${request.clubName} est transmise.`, icon: Clock } as const);

  return (
    <AuthLayout
      title={state.title}
      description={state.description}
      footer={
        <Button variant="ghost" size="sm" onClick={logout}>
          Se déconnecter
        </Button>
      }
    >
      <div className="flex flex-col items-center gap-4 py-2 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
          <state.icon className="size-6 text-accent" />
        </span>
        {deactivated ? (
          <p className="text-sm text-muted-foreground">
            Votre accès a été désactivé par un gestionnaire du club. Rapprochez-vous de lui pour le réactiver. Cette page se mettra à jour automatiquement dès la réactivation.
          </p>
        ) : request === null ? (
          <p className="text-sm text-muted-foreground">
            Le gestionnaire{data?.club ? ` de ${data.club.name}` : ""} doit approuver votre demande avant que vous puissiez accéder à l'espace du club. Cette page se mettra à jour automatiquement dès l'approbation.
          </p>
        ) : request.status === "refused" ? (
          <p className="text-sm text-muted-foreground">
            Le club ({request.ara}) a refusé la demande. Si vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club — ou recommencez l'inscription après clarification.
          </p>
        ) : request.status === "expired" ? (
          <p className="text-sm text-muted-foreground">
            Le lien envoyé au club ({request.ara}) a expiré sans réponse. Le support peut débloquer votre demande — contactez-nous, ou rapprochez-vous de votre club.
          </p>
        ) : request.clubEmailKnown ? (
          <p className="text-sm text-muted-foreground">
            Un email d'approbation a été envoyé à l'adresse officielle de votre club ({request.ara}) — c'est le club qui valide la création de son espace. Cette page se mettra à jour automatiquement dès l'approbation.
          </p>
        ) : (
          <p className="text-sm text-muted-foreground">
            Nous n'avons pas trouvé d'adresse officielle FFBB pour votre club ({request.ara}) : votre demande sera validée par notre équipe. Cette page se mettra à jour automatiquement dès l'approbation.
          </p>
        )}
      </div>
    </AuthLayout>
  );
}
