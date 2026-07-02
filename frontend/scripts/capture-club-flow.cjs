// Exercise the club identity flow: upload a logo, save, toggle mode, screenshot.
const fs = require("fs");
const puppeteer = require("puppeteer");

const BASE = process.env.SHOT_BASE || "http://frontend";
const OUT = process.env.SHOT_OUT || "/tmp/out";
const LOGO = process.env.SHOT_LOGO || "/tmp/logo64.png";
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({ headless: true, args: ["--no-sandbox", "--disable-setuid-sandbox"] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1400, height: 1000 });
  const logs = [];
  page.on("console", (m) => logs.push("console:" + m.text()));
  page.on("pageerror", (e) => logs.push("pageerror:" + e.message));
  page.on("requestfailed", (r) => logs.push("reqfail:" + r.url() + " " + r.failure()?.errorText));

  await page.goto(BASE + "/login", { waitUntil: "networkidle0", timeout: 20000 });
  await page.type("#email", "mara.mb@bccl.fr");
  await page.type("#password", "maraboubccl");
  await page.click("button[type=submit]");
  await page.waitForFunction(() => !location.pathname.includes("/login"), { timeout: 20000 });

  await page.goto(BASE + "/club", { waitUntil: "networkidle0", timeout: 20000 });
  await sleep(600);

  // Upload a logo file → opens the cropper
  const input = await page.$('input[type=file]');
  await input.uploadFile(LOGO);
  await sleep(600);
  await page.screenshot({ path: `${OUT}/club-cropper.png` });

  // Valider le cadrage
  await page.evaluate(() => {
    const b = [...document.querySelectorAll("button")].find((x) => x.textContent.trim() === "Valider le cadrage");
    if (b) b.click();
  });
  await sleep(700); // extraction on cropped image

  // Enregistrer
  const saved = await page.evaluate(() => {
    const btn = [...document.querySelectorAll("button")].find((b) => b.textContent.trim() === "Enregistrer");
    if (btn) { btn.click(); return true; }
    return false;
  });
  logs.push("clicked Enregistrer:" + saved);
  await sleep(1500);

  await page.reload({ waitUntil: "networkidle0" });
  await sleep(700);
  await page.screenshot({ path: `${OUT}/club-after-save.png` });
  const marker = await page.evaluate(() => document.documentElement.dataset.themeHook || "none");
  logs.push("theme-hook-marker:" + marker);
  const inlineDark = await page.evaluate(() => document.documentElement.style.getPropertyValue("--accent"));
  logs.push("inline-accent-after-load(dark):" + JSON.stringify(inlineDark));

  // Navigate Club -> Accueil -> Club
  await page.goto(BASE + "/", { waitUntil: "networkidle0" });
  await sleep(400);
  const inlineHome = await page.evaluate(() => document.documentElement.style.getPropertyValue("--accent"));
  logs.push("inline-accent-on-home:" + JSON.stringify(inlineHome));
  await page.goto(BASE + "/club", { waitUntil: "networkidle0" });
  await sleep(400);

  // Toggle dark/light
  await page.evaluate(() => {
    const btn = document.querySelector('button[aria-label="Basculer le thème"]');
    if (btn) btn.click();
  });
  await sleep(500);
  await page.screenshot({ path: `${OUT}/club-toggled.png` });
  const inlineLight = await page.evaluate(() => document.documentElement.style.getPropertyValue("--accent"));
  logs.push("inline-accent-after-toggle(light):" + JSON.stringify(inlineLight));
  fs.writeFileSync(`${OUT}/flow-log.txt`, logs.join("\n"));

  await browser.close();
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
