import { Check } from "lucide-react";
import { type FormEvent, useState } from "react";
import { Link } from "react-router-dom";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { NewPasswordFields } from "@/shared/components/ui/new-password-fields";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";
import { isPasswordValid } from "@/shared/lib/passwordPolicy";

import { AuthLayout } from "./AuthLayout";
import { useRegister } from "./queries";

// Miroir du serveur (AuthController: filter_var FILTER_VALIDATE_EMAIL) pour un
// retour immédiat au blur ; le serveur reste l'autorité.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Sports proposés. Basket seul aujourd'hui — les autres sont annoncés, désactivés
 *  (préparés au flux ; aucune logique multi-sport, cf. Club.sportId basket au seed). */
const SPORTS: { id: string; label: string; icon: string; enabled: boolean }[] = [
  { id: "basketball", label: "Basketball", icon: "🏀", enabled: true },
  { id: "handball", label: "Handball", icon: "🤾", enabled: false },
  { id: "volleyball", label: "Volleyball", icon: "🏐", enabled: false },
];

export function RegisterPage() {
  const register = useRegister();
  // Étape 1 = choix du sport (basket présélectionné), étape 2 = les champs du sport.
  // Le sport n'est PAS envoyé au serveur : le seul choix est basket, que createClub
  // pose côté serveur. Au 2e sport, le threader (payload → token → createClub).
  const [step, setStep] = useState<"sport" | "details">("sport");
  const [sport, setSport] = useState("basketball");
  const [form, setForm] = useState({ firstName: "", lastName: "", email: "", password: "", confirm: "", ara: "", club_name: "" });
  const [consent, setConsent] = useState(false);
  const [emailError, setEmailError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const set = (key: keyof typeof form) => (event: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: event.target.value }));

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if ("" === form.email || !EMAIL_RE.test(form.email)) {
      setError("Renseignez une adresse email valide.");
      return;
    }
    if (!isPasswordValid(form.password)) {
      setError("Le mot de passe ne respecte pas les critères.");
      return;
    }
    // La non-correspondance est déjà signalée sous le champ (NewPasswordFields) —
    // on bloque sans dupliquer le message au niveau du formulaire.
    if (form.password !== form.confirm) {
      return;
    }
    // noValidate rend le `required` de la case inerte : garde explicite avec
    // un message clair plutôt qu'un bouton désarmé silencieux (revue PR-5).
    if (!consent) {
      setError("Vous devez accepter les conditions d'utilisation et la politique de confidentialité.");
      return;
    }
    try {
      await register.mutateAsync({ firstName: form.firstName, lastName: form.lastName, email: form.email, password: form.password, ara: form.ara.toUpperCase(), club_name: form.club_name, consent });
      setSent(true);
    } catch (err) {
      setError(await apiErrorMessage(err));
    }
  }

  if (sent) {
    return (
      <AuthLayout title="Créer un compte" description="Vérifiez votre boîte mail pour activer votre compte.">
        <p className="text-sm text-muted-foreground">
          Un email de confirmation vient d'être envoyé à <span className="text-foreground">{form.email}</span>. Ouvrez le lien qu'il contient pour activer votre compte. Pensez à vérifier vos spams.
        </p>
      </AuthLayout>
    );
  }

  if ("sport" === step) {
    return (
      <AuthLayout
        title="Votre sport"
        description="Choisissez le sport de votre club. D'autres arrivent bientôt."
        footer={<>Déjà un compte ? <Link className="text-accent hover:underline" to="/login">Se connecter</Link></>}
      >
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-3 gap-3">
            {SPORTS.map((s) => (
              <button
                key={s.id}
                type="button"
                disabled={!s.enabled}
                aria-pressed={sport === s.id}
                onClick={() => setSport(s.id)}
                className={cn(
                  "relative flex flex-col items-center gap-2 rounded-lg border p-4 text-sm transition",
                  sport === s.id ? "border-accent bg-accent/10 font-medium" : "border-border hover:bg-muted",
                  s.enabled ? "" : "cursor-not-allowed opacity-40",
                )}
              >
                {sport === s.id ? <Check aria-hidden className="absolute right-1.5 top-1.5 size-3.5 text-accent" /> : null}
                <span aria-hidden className="text-2xl leading-none">{s.icon}</span>
                <span>{s.label}</span>
                {s.enabled ? null : <span className="text-[10px] text-muted-foreground">bientôt</span>}
              </button>
            ))}
          </div>
          <Button onClick={() => setStep("details")}>Continuer</Button>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Créer un compte"
      description="Le code ARA identifie votre club FFBB. S'il existe déjà, votre demande sera soumise à l'approbation du gestionnaire."
      footer={<>Déjà un compte ? <Link className="text-accent hover:underline" to="/login">Se connecter</Link></>}
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
          <Input
            id="email"
            type="email"
            autoComplete="email"
            required
            value={form.email}
            onChange={(e) => { set("email")(e); if (null !== emailError) setEmailError(null); }}
            onBlur={() => setEmailError("" !== form.email && !EMAIL_RE.test(form.email) ? "Adresse email invalide." : null)}
            aria-invalid={null !== emailError}
          />
          {emailError ? <p className="text-xs text-destructive">{emailError}</p> : null}
        </div>
        <NewPasswordFields
          password={form.password}
          confirm={form.confirm}
          onPasswordChange={(v) => setForm((prev) => ({ ...prev, password: v }))}
          onConfirmChange={(v) => setForm((prev) => ({ ...prev, confirm: v }))}
        />
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="ara">Code ARA du club</Label>
          <Input id="ara" required value={form.ara} onChange={set("ara")} placeholder="Ex. BCCL0123" className="uppercase" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="club_name">Nom du club <span className="text-muted-foreground">(si nouveau club)</span></Label>
          <Input id="club_name" value={form.club_name} onChange={set("club_name")} />
          <p className="text-xs text-muted-foreground">Récupéré automatiquement depuis la FFBB si le code est reconnu.</p>
        </div>
        {/* RGPD : consentement explicite requis (le backend le refuse sans). */}
        <label className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 size-4 accent-[var(--accent)]"
            checked={consent}
            onChange={(e) => setConsent(e.target.checked)}
            required
          />
          <span>
            J'accepte les{" "}
            <Link className="text-accent hover:underline" to="/confidentialite" target="_blank" rel="noreferrer">
              conditions d'utilisation et la politique de confidentialité
            </Link>
            .
          </span>
        </label>
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <div className="flex gap-2">
          <Button type="button" variant="outline" onClick={() => setStep("sport")}>
            Précédent
          </Button>
          <Button type="submit" className="flex-1" disabled={register.isPending}>
            {register.isPending ? <Spinner className="size-4" /> : null}
            Créer le compte
          </Button>
        </div>
      </form>
    </AuthLayout>
  );
}
