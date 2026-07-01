import { type FormEvent, useState } from "react";
import { Link } from "react-router-dom";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Spinner } from "@/shared/components/ui/spinner";

import { AuthLayout } from "./AuthLayout";
import { useForgotPassword } from "./queries";

export function ForgotPasswordPage() {
  const forgot = useForgotPassword();
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    await forgot.mutateAsync(email);
    setSent(true);
  }

  return (
    <AuthLayout
      title="Mot de passe oublié"
      description="Entrez votre email : si un compte existe, vous recevrez un lien de réinitialisation."
      footer={<Link className="text-accent hover:underline" to="/login">Retour à la connexion</Link>}
    >
      {sent ? (
        <p className="text-sm text-muted-foreground">
          Si un compte est associé à <span className="text-foreground">{email}</span>, un email de réinitialisation vient d'être envoyé. Vérifiez votre boîte de réception.
        </p>
      ) : (
        <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <Button type="submit" disabled={forgot.isPending}>
            {forgot.isPending ? <Spinner className="size-4" /> : null}
            Envoyer le lien
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
