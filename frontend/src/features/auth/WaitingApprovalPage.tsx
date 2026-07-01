import { useQuery } from "@tanstack/react-query";
import { Clock } from "lucide-react";
import { useEffect } from "react";
import { useNavigate } from "react-router-dom";

import { useAuthStore } from "@/shared/stores/authStore";
import { Button } from "@/shared/components/ui/button";

import { AuthLayout } from "./AuthLayout";
import { getMe } from "./api";
import { useLogout } from "./queries";

export function WaitingApprovalPage() {
  const navigate = useNavigate();
  const logout = useLogout();
  const token = useAuthStore((state) => state.token);

  // Poll membership status so the screen advances automatically once approved.
  const { data } = useQuery({
    queryKey: ["me"],
    queryFn: getMe,
    enabled: null !== token,
    refetchInterval: 5000,
    retry: false,
  });

  useEffect(() => {
    if (data?.membershipStatus === "active") {
      navigate("/", { replace: true });
    }
  }, [data?.membershipStatus, navigate]);

  return (
    <AuthLayout
      title="Demande en attente"
      description="Votre demande a bien été enregistrée."
      footer={
        <Button variant="ghost" size="sm" onClick={logout}>
          Se déconnecter
        </Button>
      }
    >
      <div className="flex flex-col items-center gap-4 py-2 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
          <Clock className="size-6 text-accent" />
        </span>
        <p className="text-sm text-muted-foreground">
          Le gestionnaire{data?.club ? ` de ${data.club.name}` : ""} doit approuver votre demande avant que vous puissiez accéder à l'espace du club. Cette page se mettra à jour automatiquement dès l'approbation.
        </p>
      </div>
    </AuthLayout>
  );
}
