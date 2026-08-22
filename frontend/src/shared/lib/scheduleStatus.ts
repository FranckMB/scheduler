/**
 * Le STATUT d'une génération — l'union `ScheduleStatus` et la règle « est-elle en
 * vol ? » — vit dans `shared/`, sous les features qui l'observent (planning ET
 * wizard). Descendu de `features/planning/` le 2026-08-22 (P4-123, résorption
 * AUD-FRT-21) : le flux SSE partagé (`scheduleStream`) le lisait déjà, ce qui
 * faisait remonter `shared/ → features/`.
 *
 * ⚠ L'union est la JUMELLE de l'enum PHP `App\Enum\ScheduleStatus` — gardée par
 * `TsUnionsMatchPhpEnumsTest` (CrossStack), dont la carte `MIRRORED` pointe ce
 * fichier : un cas ajouté côté serveur et oublié ici rendrait un test rouge.
 */
export type ScheduleStatus = "DRAFT" | "PENDING" | "GENERATING" | "COMPLETED" | "FAILED";

/**
 * Les statuts NON TERMINAUX d'une génération — foyer unique (D-31).
 *
 * La liste était déclarée à CINQ endroits, dont une négation inline dans le flux SSE. Un
 * statut non terminal ajouté demain et oublié quelque part : certains écrans arrêtent de
 * poller ou réactivent leurs boutons **en pleine génération**, d'autres non.
 *
 * ⚠ Elle vit ici et non dans `api.ts` : les tests d'écran mockent le module d'API, et une
 * constante qui y serait exportée disparaîtrait de leurs mocks — le module entier échouerait
 * à charger. Une RÈGLE n'a pas sa place dans le module qui parle au réseau.
 */
export const IN_FLIGHT_STATUSES: readonly ScheduleStatus[] = ["PENDING", "GENERATING"];

/** Une génération est terminée quand son statut n'est plus en vol. */
export const isTerminalStatus = (status: ScheduleStatus | null): boolean => null !== status && !IN_FLIGHT_STATUSES.includes(status);
