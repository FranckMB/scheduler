/**
 * Les rôles qui donnent accès aux écritures de gestion — miroir de
 * `ClubUserRepository::MANAGEMENT_ROLES` (D-32).
 *
 * ⚑ La liste était réécrite inline dans deux écrans (`SeasonTransitionBanner`, `ClubPage`).
 * Le sens dangereux est l'AJOUT : un rôle de gestion ajouté côté serveur et oublié ici, et
 * les deux écrans continuent de **masquer** une capacité que le backend autorise —
 * la fonctionnalité existe, elle est invisible, et rien ne le signale. (Le retrait, lui,
 * est bruyant : l'écran offre un geste que l'API refuse.)
 *
 * ⚠ C'est un miroir d'AFFICHAGE, jamais une autorisation : le serveur reste seul juge
 * (`ManagementAccessGuard`, SEC-07). Il sert à ne pas proposer un geste voué au 403.
 * La parité avec le backend est tenue par `ManagementRolesMatchBackendTest`.
 */
export const MANAGEMENT_ROLES = ["owner", "admin"] as const;

/** Ce membre peut-il déclencher les écritures de gestion ? (affichage seul) */
export const isManagementRole = (role: string | null | undefined): boolean =>
  null != role && (MANAGEMENT_ROLES as readonly string[]).includes(role);

/**
 * Les rôles ASSIGNABLES d'une adhésion (P1-1) — miroir de l'enum PHP `ClubRole`.
 * Ordre = privilège décroissant (Gestionnaire d'abord). C'est la liste qu'un
 * gestionnaire peut CHOISIR (approbation, changement de rôle) ; toute écriture est
 * validée serveur contre `ClubRole` (422 hors liste). Distincte de
 * `MANAGEMENT_ROLES` : 'owner' est LU comme gestion mais n'est ni proposé ni assigné.
 *
 * ⚠ Parité tenue par `ManagementRolesMatchBackendTest` (groupe contract) : cette
 * liste et `ClubRole::cases()` doivent coïncider dans les DEUX sens.
 */
export const ASSIGNABLE_ROLES = ["admin", "member"] as const;

export type AssignableRole = (typeof ASSIGNABLE_ROLES)[number];

const ROLE_LABELS: Record<string, string> = {
  admin: "Gestionnaire",
  member: "Membre",
  owner: "Gestionnaire",
};

/** Libellé français d'un rôle d'adhésion (affichage) — repli sur le code brut si inconnu. */
export const roleLabel = (role: string): string => ROLE_LABELS[role] ?? role;
