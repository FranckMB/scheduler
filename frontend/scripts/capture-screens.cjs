// Screenshot the frontend auth screens. Runs inside the pdf-worker container
// (which ships puppeteer + chromium), targeting the prod frontend on the Docker
// network — no host browser / root needed. Driven by capture-screens.sh.
//   NODE_PATH=/home/pptruser/node_modules node capture-screens.cjs
const fs = require("fs");
const puppeteer = require("puppeteer");

const BASE = process.env.SHOT_BASE || "http://frontend";
const OUT = process.env.SHOT_OUT || "/tmp/out";
const routes = [
  ["login", "/login"],
  ["register", "/register"],
  ["forgot", "/forgot-password"],
  ["waiting", "/waiting"],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({ headless: true, args: ["--no-sandbox", "--disable-setuid-sandbox"] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1200, height: 820 });
  for (const [name, path] of routes) {
    await page.goto(BASE + path, { waitUntil: "networkidle0", timeout: 20000 });
    await new Promise((r) => setTimeout(r, 500));
    await page.screenshot({ path: `${OUT}/cs-${name}.png` });
    console.log("shot", name);
  }
  await browser.close();
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
