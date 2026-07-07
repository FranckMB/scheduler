/** Mirrors backend App\Service\PasswordPolicy for live client-side feedback.
 *  The server remains the authority; this only improves UX. Rules: ≥12 chars,
 *  ≥1 uppercase, ≥1 special (non-alphanumeric) character. */

export const PASSWORD_MIN_LENGTH = 12;

export const PASSWORD_REQUIREMENT = "Au moins 12 caractères, une majuscule et un caractère spécial.";

/** @returns a French error string when too weak, or null when it complies. */
export function validatePassword(password: string): string | null {
  if ([...password].length < PASSWORD_MIN_LENGTH) return PASSWORD_REQUIREMENT;
  if (!/\p{Lu}/u.test(password)) return PASSWORD_REQUIREMENT;
  if (!/[^\p{L}\p{N}]/u.test(password)) return PASSWORD_REQUIREMENT;

  return null;
}

export function isPasswordValid(password: string): boolean {
  return null === validatePassword(password);
}
