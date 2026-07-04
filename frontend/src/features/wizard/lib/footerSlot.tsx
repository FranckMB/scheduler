import { createContext, type ReactNode, useContext } from "react";

interface WizardFooterApi {
  /** Inject an action (e.g. "Trier") into the wizard's sticky footer, left of Suivant. */
  setFooterExtra: (node: ReactNode) => void;
  /** Hide the floating scroll-jump arrows (e.g. during Teams drag-reorder). */
  setSuppressScrollJump: (v: boolean) => void;
}

export const WizardFooterContext = createContext<WizardFooterApi>({ setFooterExtra: () => {}, setSuppressScrollJump: () => {} });

export const useWizardFooter = (): WizardFooterApi => useContext(WizardFooterContext);
