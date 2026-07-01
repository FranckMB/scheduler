import { type FormEvent, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Spinner } from "@/shared/components/ui/spinner";

import { AuthLayout } from "./AuthLayout";
import { useResetPassword } from "./queries";

export function ResetPasswordPage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const reset = useResetPassword();
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      await reset.mutateAsync({ token, password });
      navigate("/login", { replace: true });
    } catch (err) {
      setError(await apiErrorMessage(err));
    }
  }

  return (
    <AuthLayout
      title="Nouveau mot de passe"
      description="Choisissez un nouveau mot de passe pour votre compte."
      footer={<Link className="text-accent hover:underline" to="/login">Retour à la connexion</Link>}
    >
      <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="password">Nouveau mot de passe</Label>
          <Input id="password" type="password" autoComplete="new-password" required minLength={8} value={password} onChange={(e) => setPassword(e.target.value)} />
          <p className="text-xs text-muted-foreground">8 caractères minimum.</p>
        </div>
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <Button type="submit" disabled={reset.isPending}>
          {reset.isPending ? <Spinner className="size-4" /> : null}
          Réinitialiser
        </Button>
      </form>
    </AuthLayout>
  );
}
