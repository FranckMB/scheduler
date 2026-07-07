import { type FormEvent, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Label } from "@/shared/components/ui/label";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { Spinner } from "@/shared/components/ui/spinner";
import { PASSWORD_REQUIREMENT, validatePassword } from "@/shared/lib/passwordPolicy";

import { AuthLayout } from "./AuthLayout";
import { useResetPassword } from "./queries";

export function ResetPasswordPage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const reset = useResetPassword();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    const passwordError = validatePassword(password);
    if (null !== passwordError) {
      setError(passwordError);
      return;
    }
    if (password !== confirm) {
      setError("Les deux mots de passe ne sont pas identiques.");
      return;
    }
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
          <PasswordInput id="password" autoComplete="new-password" required minLength={12} value={password} onChange={(e) => setPassword(e.target.value)} />
          <p className="text-xs text-muted-foreground">{PASSWORD_REQUIREMENT}</p>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="confirm">Confirmer le mot de passe</Label>
          <PasswordInput id="confirm" autoComplete="new-password" required value={confirm} onChange={(e) => setConfirm(e.target.value)} />
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
