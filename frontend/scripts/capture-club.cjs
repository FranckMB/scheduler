// Screenshot the club management screen + accent applied (FakeClub / n.eblin).
const fs = require("fs");
const puppeteer = require("puppeteer");

const BASE = process.env.SHOT_BASE || "http://frontend";
const OUT = process.env.SHOT_OUT || "/tmp/out";
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({ headless: true, args: ["--no-sandbox", "--disable-setuid-sandbox"] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1400, height: 1000 });

  await page.goto(BASE + "/login", { waitUntil: "networkidle0", timeout: 20000 });
  await page.type("#email", "mara.mb@bccl.fr");
  await page.type("#password", "maraboubccl");
  await page.click("button[type=submit]");
  await page.waitForFunction(() => !location.pathname.includes("/login"), { timeout: 20000 });

  await page.goto(BASE + "/club", { waitUntil: "networkidle0", timeout: 20000 });
  await sleep(900);
  await page.screenshot({ path: `${OUT}/club-identity.png` });
  console.log("shot club-identity");

  await browser.close();
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
