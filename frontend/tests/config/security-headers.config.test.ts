import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * A17 config-regression guard that runs in CI (the runtime e2e can't — CI drives
 * Playwright against the Vite dev server, which serves no nginx headers). Reads
 * the nginx configs directly and asserts the security headers + CSP directives
 * are present and correctly scoped, so removing a header or a `blob:` allowance
 * fails a fast unit test instead of silently shipping.
 */
const root = resolve(process.cwd(), "..");
const read = (p: string): string => readFileSync(resolve(root, p), "utf8");

/** Directive lines only — drop `#` comments so prose mentioning a header/CSP token never matches. */
const directivesOf = (conf: string): string =>
  conf
    .split("\n")
    .map((l) => l.trim())
    .filter((l) => l.length > 0 && !l.startsWith("#"))
    .join("\n");

const base = directivesOf(read("docker/frontend/security-headers.conf"));
const cspConf = directivesOf(read("docker/frontend/csp.conf"));
const frontendConf = read("docker/frontend/nginx.conf");
const backendConf = directivesOf(read("docker/nginx/default.conf"));

/** The single `Content-Security-Policy "…"` value from csp.conf. */
const cspValue = /Content-Security-Policy\s+"([^"]+)"/.exec(cspConf)?.[1] ?? "";
const cspDirective = (name: string): string => new RegExp(`(?:^|;)\\s*${name}\\s+([^;]*)`).exec(cspValue)?.[1]?.trim() ?? "";

describe("A17 baseline security headers (docker/frontend/security-headers.conf)", () => {
  it("declares the four baseline headers", () => {
    expect(base).toMatch(/add_header\s+X-Frame-Options\s+"DENY"/);
    expect(base).toMatch(/add_header\s+X-Content-Type-Options\s+"nosniff"/);
    expect(base).toMatch(/add_header\s+Referrer-Policy\s+"same-origin"/);
    expect(base).toMatch(/add_header\s+Strict-Transport-Security\s+"max-age=\d+"/);
  });

  it("keeps HSTS without includeSubDomains and carries no CSP", () => {
    expect(base).not.toMatch(/includeSubDomains/);
    expect(base).not.toMatch(/Content-Security-Policy/);
  });
});

describe("A17 CSP (docker/frontend/csp.conf)", () => {
  it("locks default/script/object and blocks framing", () => {
    expect(cspDirective("default-src")).toBe("'self'");
    expect(cspDirective("script-src")).toBe("'self'");
    expect(cspDirective("object-src")).toBe("'none'");
    expect(cspDirective("frame-ancestors")).toBe("'none'");
    expect(cspDirective("base-uri")).toBe("'self'");
  });

  it("allows the exact relaxations the app needs (and no more)", () => {
    expect(cspDirective("style-src")).toBe("'self' 'unsafe-inline'"); // React inline styles
    expect(cspDirective("img-src")).toContain("blob:"); // logo cropper preview / palette
    expect(cspDirective("connect-src")).toContain("blob:"); // logo recrop fetch()
    expect(cspDirective("script-src")).not.toContain("unsafe-inline"); // bundled module only
    expect(cspValue).not.toContain("unsafe-eval");
  });
});

describe("A17 wiring (nginx.conf)", () => {
  it("adds the baseline at the frontend server level (covers /api, /exports, nginx errors)", () => {
    // Everything before the first `location …{` block is server-level config.
    const serverPrefix = frontendConf.slice(0, frontendConf.search(/\n\s*location\s/));
    expect(serverPrefix).toContain("include /etc/nginx/snippets/security-headers.conf;");
  });

  it("scopes the CSP to HTML only — never onto the /api or /exports proxies", () => {
    expect(frontendConf).toContain("include /etc/nginx/snippets/csp.conf;");
    const apiBlock = /location \/api\/ \{[^}]*\}/s.exec(frontendConf)?.[0] ?? "";
    const exportsBlock = /location \/exports\/ \{[^}]*\}/s.exec(frontendConf)?.[0] ?? "";
    expect(apiBlock).not.toContain("csp.conf");
    expect(exportsBlock).not.toContain("csp.conf");
  });

  it("backend nginx sets no security header (single source = the frontend edge)", () => {
    expect(backendConf).not.toMatch(/add_header\s+X-Frame-Options/);
    expect(backendConf).not.toMatch(/add_header\s+Content-Security-Policy/);
  });
});
