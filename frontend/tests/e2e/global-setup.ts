import { execSync } from "node:child_process";

/**
 * The register endpoint is rate-limited (5/15min sliding, keyed on IP) and the
 * whole e2e suite registers ~8 clubs from the same browser IP — without a
 * purge, later tests get throttled and fail flaky.
 *
 * ⚠ NOT a FLUSHALL: Redis also carries the Messenger transport stream
 * ("messages" + its consumer group — flushing it crash-loops the worker with
 * NOGROUP and generations never complete) and the generation locks
 * ("schedule_generation:*"). Only the app-cache keys (opaque hashed prefixes,
 * where the sliding-window limiter state lives) are deleted.
 */
export default function globalSetup(): void {
  const cwd = `${import.meta.dirname}/../../..`;
  try {
    const keys = execSync("docker compose exec -T redis redis-cli --scan", { cwd, stdio: "pipe", timeout: 15_000 })
      .toString()
      .split("\n")
      .map((k) => k.trim())
      .filter((k) => k !== "" && k !== "messages" && !k.startsWith("schedule_generation:"));
    for (const key of keys) {
      execSync(`docker compose exec -T redis redis-cli DEL ${JSON.stringify(key)}`, { cwd, stdio: "pipe", timeout: 15_000 });
    }
  } catch (error) {
    console.warn(`[e2e] Redis limiter purge skipped (${(error as Error).message.split("\n")[0]}) — register rate-limits may bite.`);
  }
}
