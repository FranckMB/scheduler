import { execSync } from "node:child_process";

/**
 * E2E preflight — bring the whole Docker stack up and healthy before any test.
 *
 * The recurring e2e failures were never the tests: they were a service left
 * stopped (a `messenger-worker` that never consumes the generation message → the
 * planning never appears; a stopped `engine` → no solve). Every compose service
 * declares a healthcheck, so `docker compose up -d --wait` starts whatever is
 * down and BLOCKS until each healthcheck passes — and is a fast no-op when the
 * stack is already healthy.
 *
 * Skipped when E2E_BASE_URL points the suite at an externally managed stack
 * (a remote target); there we cannot and should not touch its containers.
 */
async function globalSetup(): Promise<void> {
  if (process.env.E2E_BASE_URL) {
    console.log(`[e2e] E2E_BASE_URL=${process.env.E2E_BASE_URL} — skipping local stack preflight.`);
    return;
  }

  console.log("[e2e] Preflight: docker compose up -d --wait (starts any stopped service, waits for healthy)…");
  try {
    // Playwright runs from frontend/; the compose file and .env live at the repo root.
    execSync("docker compose up -d --wait", { cwd: "..", stdio: "inherit" });
  } catch (error) {
    throw new Error(
      "Le stack Docker n'est pas prêt : `docker compose up -d --wait` a échoué. " +
        "Lancez `make start` à la racine (ou vérifiez que Docker tourne), puis relancez les tests e2e.",
      { cause: error },
    );
  }
}

export default globalSetup;
