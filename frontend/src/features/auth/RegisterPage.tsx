import { type FormEvent, useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { Spinner } from "@/shared/components/ui/spinner";
import { PASSWORD_REQUIREMENT, validatePassword } from "@/shared/lib/passwordPolicy";

import { AuthLayout } from "./AuthLayout";
import { useRegister } from "./queries";

export function RegisterPage() {
  const navigate = useNavigate();
  const register = useRegister();
  const [form, setForm] = useState({ firstName: "", lastName: "", email: "", password: "", ara: "", club_name: "" });
  const [error, setError] = useState<string | null>(null);

  const set = (key: keyof typeof form) => (event: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: event.target.value }));

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    const passwordError = validatePassword(form.password);
    if (null !== passwordError) {
      setError(passwordError);
      return;
    }
    try {
      const result = await register.mutateAsync({ ...form, ara: form.ara.toUpperCase() });
      navigate(result.membershipStatus === "pending" ? "/waiting" : "/", { replace: true });
    } catch (err) {
      setError(await apiErrorMessage(err));
    }
  }

  return (
    <AuthLayout
      title="Créer un compte"
      description="Le code ARA identifie votre club FFBB. S'il existe déjà, votre demande sera soumise à l'approbation du gestionnaire."
      footer={
        <>
          Déjà un compte ? <Link className="text-accent hover:underline" to="/login">Se connecter</Link>
        </>
      }
    >
      <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="firstName">Prénom</Label>
            <Input id="firstName" required value={form.firstName} onChange={set("firstName")} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="lastName">Nom</Label>
            <Input id="lastName" required value={form.lastName} onChange={set("lastName")} />
          </div>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" autoComplete="email" required value={form.email} onChange={set("email")} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="password">Mot de passe</Label>
          <PasswordInput id="password" autoComplete="new-password" required minLength={12} value={form.password} onChange={set("password")} />
          <p className="text-xs text-muted-foreground">{PASSWORD_REQUIREMENT}</p>
          <p className="text-xs text-muted-foreground">8 caractères minimum.</p>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="ara">Code ARA du club</Label>
          <Input id="ara" required value={form.ara} onChange={set("ara")} placeholder="Ex. BCCL0123" className="uppercase" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="club_name">Nom du club <span className="text-muted-foreground">(si nouveau club)</span></Label>
          <Input id="club_name" value={form.club_name} onChange={set("club_name")} />
        </div>
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <Button type="submit" disabled={register.isPending}>
          {register.isPending ? <Spinner className="size-4" /> : null}
          Créer le compte
        </Button>
      </form>
    </AuthLayout>
  );
}
