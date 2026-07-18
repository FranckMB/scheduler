/** Mirrors backend App\Service\PasswordPolicy for live client-side feedback.
 *  The server remains the authority; this only improves UX. Rules: ≥12 chars,
 *  ≥1 uppercase, ≥1 special (non-alphanumeric) character. */

export const PASSWORD_MIN_LENGTH = 12;

export const PASSWORD_REQUIREMENT = "Au moins 12 caractères, une majuscule et un caractère spécial.";

/** Per-rule results for the live checklist (register/reset/profile). */
export interface PasswordChecks {
  length: boolean;
  upper: boolean;
  special: boolean;
}

/** Evaluate each rule independently — drives the live green/grey checklist. */
export function passwordChecks(password: string): PasswordChecks {
  return {
    length: [...password].length >= PASSWORD_MIN_LENGTH,
    upper: /\p{Lu}/u.test(password),
    special: /[^\p{L}\p{N}]/u.test(password),
  };
}

/** @returns a French error string when too weak, or null when it complies. */
export function validatePassword(password: string): string | null {
  const c = passwordChecks(password);

  return c.length && c.upper && c.special ? null : PASSWORD_REQUIREMENT;
}

export function isPasswordValid(password: string): boolean {
  return null === validatePassword(password);
}
