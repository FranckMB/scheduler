import type { ScheduleStatus } from "../api";

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
