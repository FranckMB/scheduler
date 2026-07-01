// Screenshot the planning dashboard (tranche 2) against a seeded club that has a
// COMPLETED schedule. Runs inside the pdf-worker container (puppeteer + chromium)
// on the Docker network. Logs in as the fixtures admin, then captures the grid
// in each view + a slot detail. Driven by capture-planning.sh.
const fs = require("fs");
const puppeteer = require("puppeteer");

const BASE = process.env.SHOT_BASE || "http://frontend";
const OUT = process.env.SHOT_OUT || "/tmp/out";
const EMAIL = process.env.SHOT_EMAIL || "mara.mb@bccl.fr";
const PASSWORD = process.env.SHOT_PASSWORD || "maraboubccl";

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function clickByText(page, text) {
  const handle = await page.evaluateHandle((t) => {
    const el = [...document.querySelectorAll("button")].find((b) => b.textContent.trim() === t);
    if (el) el.click();
    return Boolean(el);
  }, text);
  return handle.jsonValue();
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({ headless: true, args: ["--no-sandbox", "--disable-setuid-sandbox"] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1400, height: 900 });

  // Login.
  await page.goto(BASE + "/login", { waitUntil: "networkidle0", timeout: 20000 });
  await page.type("#email", EMAIL);
  await page.type("#password", PASSWORD);
  await page.click("button[type=submit]");

  // Wait for the planning page + its data.
  await page.waitForFunction(() => document.querySelector("h1")?.textContent?.includes("Planning"), { timeout: 20000 });
  await page.waitForFunction(() => document.querySelector(".grid button") || document.body.textContent.includes("Planning vide"), {
    timeout: 20000,
  });
  await sleep(800);
  await page.screenshot({ path: `${OUT}/planning-gymnase.png` });
  console.log("shot gymnase");

  await clickByText(page, "Par coach");
  await sleep(600);
  await page.screenshot({ path: `${OUT}/planning-coach.png` });
  console.log("shot coach");

  await clickByText(page, "Par équipe");
  await sleep(600);
  await page.screenshot({ path: `${OUT}/planning-equipe.png` });
  console.log("shot equipe");

  // Back to gymnase + open a slot detail.
  await clickByText(page, "Par gymnase");
  await sleep(400);
  const clicked = await page.evaluate(() => {
    const slot = document.querySelector(".grid button");
    if (slot) slot.click();
    return Boolean(slot);
  });
  await sleep(500);
  await page.screenshot({ path: `${OUT}/planning-slot-detail.png` });
  console.log("shot slot-detail", clicked);

  // Expand the first diagnostics group to reveal when + which teams conflict.
  await page.evaluate(() => {
    const el = [...document.querySelectorAll("button")].find((b) => /conflict|unused|warning/i.test(b.textContent));
    if (el) el.click();
  });
  await sleep(500);
  await page.screenshot({ path: `${OUT}/planning-diagnostics.png` });
  console.log("shot diagnostics");

  await browser.close();
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
