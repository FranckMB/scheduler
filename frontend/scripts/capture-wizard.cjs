// Screenshot the wizard (tranche 3) against the seeded fixtures club.
const fs = require("fs");
const puppeteer = require("puppeteer");

const BASE = process.env.SHOT_BASE || "http://frontend";
const OUT = process.env.SHOT_OUT || "/tmp/out";
const EMAIL = process.env.SHOT_EMAIL || "mara.mb@bccl.fr";
const PASSWORD = process.env.SHOT_PASSWORD || "maraboubccl";
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({ headless: true, args: ["--no-sandbox", "--disable-setuid-sandbox"] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1400, height: 1100 });

  await page.goto(BASE + "/login", { waitUntil: "networkidle0", timeout: 20000 });
  await page.type("#email", EMAIL);
  await page.type("#password", PASSWORD);
  await page.click("button[type=submit]");
  await page.waitForFunction(() => document.querySelector("h1")?.textContent?.includes("Planning"), { timeout: 20000 });

  await page.goto(BASE + "/wizard", { waitUntil: "networkidle0", timeout: 20000 });
  await page.waitForFunction(() => document.querySelector("h2")?.textContent?.includes("Équipes"), { timeout: 20000 });
  await sleep(800);
  await page.screenshot({ path: `${OUT}/wizard-teams.png` });
  console.log("shot wizard-teams");

  const goStep = async (label) => {
    await page.evaluate((t) => {
      const el = [...document.querySelectorAll("nav button")].find((b) => b.textContent.includes(t));
      if (el) el.click();
    }, label);
    await sleep(700);
  };

  await goStep("Gymnases");
  await page.screenshot({ path: `${OUT}/wizard-venues.png` });
  console.log("shot wizard-venues");

  await goStep("Coachs");
  await page.screenshot({ path: `${OUT}/wizard-coaches.png` });
  console.log("shot wizard-coaches");

  await goStep("Contraintes");
  await page.screenshot({ path: `${OUT}/wizard-constraints.png` });
  console.log("shot wizard-constraints");

  await browser.close();
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
