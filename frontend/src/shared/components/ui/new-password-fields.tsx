import { Check, X } from "lucide-react";

import { Label } from "@/shared/components/ui/label";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { cn } from "@/shared/lib/utils";
import { passwordChecks } from "@/shared/lib/passwordPolicy";

interface NewPasswordFieldsProps {
  password: string;
  confirm: string;
  onPasswordChange: (value: string) => void;
  onConfirmChange: (value: string) => void;
  /** Distinguish the field ids when two instances could collide on a page. */
  idPrefix?: string;
  passwordLabel?: string;
}

const RULES: { key: "length" | "upper" | "special"; label: string }[] = [
  { key: "length", label: "Au moins 12 caractères" },
  { key: "upper", label: "Une majuscule" },
  { key: "special", label: "Un caractère spécial" },
];

/**
 * Champs « nouveau mot de passe + confirmation » partagés (register, reset,
 * profil) : checklist LIVE par critère (verte dès qu'il passe) + contrôle de
 * correspondance pour attraper une faute de frappe (retour fondateur 2026-07-18).
 * Contrôlé par le parent, qui décide de la validité globale via
 * `isPasswordValid(password) && password === confirm`.
 */
export function NewPasswordFields({ password, confirm, onPasswordChange, onConfirmChange, idPrefix = "", passwordLabel = "Mot de passe" }: NewPasswordFieldsProps) {
  const checks = passwordChecks(password);
  const pwId = `${idPrefix}password`;
  const confirmId = `${idPrefix}confirm`;
  // La non-correspondance ne s'affiche qu'une fois la confirmation entamée.
  const mismatch = confirm.length > 0 && password !== confirm;

  return (
    <>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={pwId}>{passwordLabel}</Label>
        <PasswordInput id={pwId} autoComplete="new-password" required minLength={12} value={password} onChange={(e) => onPasswordChange(e.target.value)} />
        <ul className="mt-0.5 space-y-0.5" aria-label="Critères du mot de passe">
          {RULES.map(({ key, label }) => {
            const ok = checks[key];
            return (
              <li key={key} className={cn("flex items-center gap-1.5 text-xs", ok ? "text-green-600 dark:text-green-500" : "text-muted-foreground")}>
                {ok ? <Check aria-hidden className="size-3.5" /> : <X aria-hidden className="size-3.5" />}
                <span>{label}</span>
                <span className="sr-only">{ok ? " (validé)" : " (manquant)"}</span>
              </li>
            );
          })}
        </ul>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={confirmId}>Confirmer le mot de passe</Label>
        <PasswordInput id={confirmId} autoComplete="new-password" required value={confirm} onChange={(e) => onConfirmChange(e.target.value)} aria-invalid={mismatch} />
        {mismatch ? <p className="text-xs text-destructive">Les deux mots de passe ne sont pas identiques.</p> : null}
      </div>
    </>
  );
}
