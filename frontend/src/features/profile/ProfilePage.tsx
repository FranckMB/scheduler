import { type FormEvent, useState } from "react";

import { useLogout, useMe } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { isPasswordValid, PASSWORD_REQUIREMENT } from "@/shared/lib/passwordPolicy";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { toast } from "@/shared/stores/toastStore";

import { useChangePassword, useDeleteAccount, useDownloadMyData, useUpdateProfile } from "./queries";

function ProfileForm({ firstName, lastName, email }: { firstName: string; lastName: string; email: string }) {
  const update = useUpdateProfile();
  const logout = useLogout();
  const [first, setFirst] = useState(firstName);
  const [last, setLast] = useState(lastName);
  const [mail, setMail] = useState(email);

  const dirty = first.trim() !== firstName || last.trim() !== lastName || mail.trim() !== email;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const emailChanged = mail.trim() !== email;
    update.mutate(
      { firstName: first.trim(), lastName: last.trim(), email: mail.trim() },
      {
        onSuccess: () => {
          // Changing the email invalidates the current session (identity = email):
          // sign out so the user re-logs in with the new address.
          if (emailChanged) {
            toast.info("E-mail modifié — reconnectez-vous.");
            logout();
          }
        },
      },
    );
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Mes informations</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={submit}>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label htmlFor="firstName">Prénom</Label>
              <Input id="firstName" value={first} onChange={(e) => setFirst(e.target.value)} required />
            </div>
            <div className="space-y-1">
              <Label htmlFor="lastName">Nom</Label>
              <Input id="lastName" value={last} onChange={(e) => setLast(e.target.value)} required />
            </div>
          </div>
          <div className="space-y-1">
            <Label htmlFor="email">E-mail</Label>
            <Input id="email" type="email" value={mail} onChange={(e) => setMail(e.target.value)} required />
            <p className="text-xs text-muted-foreground">Changer l'e-mail vous déconnectera (à utiliser pour vous reconnecter).</p>
          </div>
          <Button type="submit" disabled={!dirty || update.isPending}>
            {update.isPending ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function PasswordForm() {
  const change = useChangePassword();
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");

  const submit = (event: FormEvent) => {
    event.preventDefault();
    change.mutate(
      { currentPassword: current, newPassword: next },
      {
        onSuccess: () => {
          setCurrent("");
          setNext("");
        },
      },
    );
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Mot de passe</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={submit}>
          <div className="space-y-1">
            <Label htmlFor="current">Mot de passe actuel</Label>
            <PasswordInput id="current" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
          </div>
          <div className="space-y-1">
            <Label htmlFor="next">Nouveau mot de passe</Label>
            <PasswordInput id="next" autoComplete="new-password" minLength={12} value={next} onChange={(e) => setNext(e.target.value)} required />
            <p className="text-xs text-muted-foreground">{PASSWORD_REQUIREMENT}</p>
          </div>
          <Button type="submit" disabled={current === "" || !isPasswordValid(next) || change.isPending}>
            {change.isPending ? <Spinner className="size-4" /> : null}
            Changer le mot de passe
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

/** RGPD — portabilité (art. 20) : télécharger ses données de compte en JSON. */
function ExportSection() {
  const exportDownload = useDownloadMyData();
  return (
    <Card>
      <CardHeader>
        <CardTitle>Mes données</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-sm text-muted-foreground">
          Téléchargez une copie de vos données personnelles (identité de compte et adhésions) au format JSON.
        </p>
        <Button
          type="button"
          variant="outline"
          disabled={exportDownload.isPending}
          onClick={() => exportDownload.mutate()}
        >
          {exportDownload.isPending ? <Spinner className="size-4" /> : null}
          Exporter mes données
        </Button>
      </CardContent>
    </Card>
  );
}

/**
 * RGPD — droit à l'effacement, self-service. Confirmation = ré-authentification
 * (mot de passe courant, patron changement de mot de passe) : un JWT volé ne
 * suffit pas à détruire le compte. L'anonymisation est immédiate ; sans autre
 * membre actif, les données du club sont supprimées après un délai de grâce de
 * 30 jours (annulé si un membre revient avant l'échéance).
 */
function DangerZone() {
  const deleteAccount = useDeleteAccount();
  const logout = useLogout();
  const [password, setPassword] = useState("");

  const submit = (event: FormEvent) => {
    event.preventDefault();
    deleteAccount.mutate(password, {
      onSuccess: (result) => {
        toast.info(
          result.clubPurgeScheduled
            ? `Compte supprimé. Sans autre membre actif, les données du club seront effacées dans ${result.gracePeriodDays} jours.`
            : "Compte supprimé.",
        );
        logout();
      },
    });
  };

  return (
    <Card className="border-destructive/40">
      <CardHeader>
        <CardTitle className="text-destructive">Supprimer mon compte</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="space-y-3" onSubmit={submit}>
          <p className="text-sm text-muted-foreground">
            Action <strong>irréversible</strong> : vos données personnelles sont anonymisées immédiatement. Si vous êtes
            le dernier membre actif, les données du club seront supprimées après un délai de 30 jours (seule la fiche
            publique FFBB du club est conservée).
          </p>
          <div className="space-y-1">
            <Label htmlFor="deletePassword">Confirmez avec votre mot de passe</Label>
            <PasswordInput
              id="deletePassword"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </div>
          <Button type="submit" variant="destructive" disabled={password === "" || deleteAccount.isPending}>
            {deleteAccount.isPending ? <Spinner className="size-4" /> : null}
            Supprimer définitivement mon compte
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export function ProfilePage() {
  const { data, isLoading } = useMe();

  if (isLoading || !data) {
    return <FullPageSpinner />;
  }

  return (
    <div className="mx-auto max-w-lg space-y-4">
      <div>
        <h1 className="border-l-[3px] border-accent pl-3 text-xl font-semibold">Profil</h1>
        <p className="text-sm text-muted-foreground">
          {data.club?.name ?? "—"} · {data.role ?? "—"}
        </p>
      </div>
      <ProfileForm firstName={data.firstName} lastName={data.lastName} email={data.email} />
      <PasswordForm />
      <ExportSection />
      <DangerZone />
    </div>
  );
}
