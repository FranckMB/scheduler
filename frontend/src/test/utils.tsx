import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import { expect } from "vitest";
import type { ReactElement } from "react";
import { MemoryRouter } from "react-router-dom";
import { axe } from "vitest-axe";

/** Render a component with the providers pages depend on (Query + Router). */
export function renderWithProviders(ui: ReactElement, { route = "/" }: { route?: string } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

/**
 * WCAG 2.2 AA guardrail: render `ui` with the app providers and assert axe-core
 * finds no violations. jsdom has no layout engine, so axe auto-skips the
 * contrast check here — colour-contrast (WCAG 1.4.3) is enforced separately by
 * the Playwright/axe pass (PR2). This covers the structural rules: roles, names,
 * labels, aria validity, duplicate ids, keyboard semantics.
 */
export async function expectNoA11yViolations(ui: ReactElement, opts?: { route?: string }): Promise<void> {
  const { container } = renderWithProviders(ui, opts);
  expect(await axe(container)).toHaveNoViolations();
}
