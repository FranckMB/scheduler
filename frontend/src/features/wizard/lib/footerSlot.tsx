import { createContext, type ReactNode, useContext } from "react";

/** Lets a step inject an action (e.g. "Trier") into the wizard's sticky footer, left of Suivant. */
export const WizardFooterContext = createContext<{ setFooterExtra: (node: ReactNode) => void }>({ setFooterExtra: () => {} });

export const useWizardFooter = (): { setFooterExtra: (node: ReactNode) => void } => useContext(WizardFooterContext);
