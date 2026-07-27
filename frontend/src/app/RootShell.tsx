import { Outlet, useNavigation } from "react-router";

/**
 * La racine technique du routeur : elle ne rend aucune UI propre, elle porte le
 * retour d'attente de TOUTE navigation.
 *
 * Depuis le découpage en chunks (P4-6), un clic de navigation attend le réseau.
 * Le premier jet ne l'indiquait que dans `AppLayout` : toute la zone non
 * authentifiée passée en lazy (inscription, mot de passe oublié, vérification
 * d'email, page publique de doléances, connexion superadmin) restait sans le
 * moindre signe — or c'est exactement là que l'utilisateur est le plus perdu,
 * et le tunnel d'inscription enchaîne ces étapes.
 */
export function RootShell() {
  const navigating = "idle" !== useNavigation().state;

  return (
    <>
      {navigating ? <NavigationPending /> : null}
      <Outlet />
    </>
  );
}

/**
 * Barre fine indéterminée (on ne connaît pas la taille du chunk restant), doublée
 * d'un texte pour lecteurs d'écran : une région `role="status"` VIDE n'annonce
 * rien du tout — elle donnait l'illusion de l'accessibilité sans la fournir.
 */
function NavigationPending() {
  return (
    <div className="fixed inset-x-0 top-0 z-50" role="status" aria-live="polite">
      <div className="h-0.5 w-full overflow-hidden bg-transparent">
        <div className="h-full w-1/3 animate-[loading-bar_1s_ease-in-out_infinite] bg-accent" />
      </div>
      <span className="sr-only">Chargement de la page…</span>
    </div>
  );
}
