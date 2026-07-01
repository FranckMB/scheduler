import { type FormEvent, useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Spinner } from "@/shared/components/ui/spinner";

import { AuthLayout } from "./AuthLayout";
import { useLogin } from "./queries";

export function LoginPage() {
  const navigate = useNavigate();
  const login = useLogin();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      await login.mutateAsync({ email, password });
      // AuthGuard routes to the app / waiting screen based on membership status.
      navigate("/", { replace: true });
    } catch (err) {
      setError(await apiErrorMessage(err));
    }
  }

  return (
    <AuthLayout
      title="Connexion"
      description="Accédez à l'espace de gestion de votre club."
      footer={
        <>
          Pas encore de compte ? <Link className="text-accent hover:underline" to="/register">Créer un compte</Link>
        </>
      }
    >
      <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1.5">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Mot de passe</Label>
            <Link className="text-xs text-muted-foreground hover:text-accent hover:underline" to="/forgot-password">
              Oublié ?
            </Link>
          </div>
          <Input id="password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <Button type="submit" disabled={login.isPending}>
          {login.isPending ? <Spinner className="size-4" /> : null}
          Se connecter
        </Button>
      </form>
    </AuthLayout>
  );
}
