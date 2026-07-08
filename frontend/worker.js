import http from 'http';
import fs from 'fs';
import path from 'path';
import puppeteer from 'puppeteer';

const PORT = 3000;
const OUTPUT_DIR = '/app/backend/public/exports';

// A4 in CSS px at 96dpi. Landscape swaps the two. ~24px of margin each way.
const A4 = { w: 794, h: 1123 };
const MARGIN = 24;

async function generateFiles(html, filename, landscape) {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });

  const pageW = landscape ? A4.h : A4.w;
  const pageH = landscape ? A4.w : A4.h;
  const availW = pageW - 2 * MARGIN;
  const availH = pageH - 2 * MARGIN;

  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  try {
    const page = await browser.newPage();
    // Lay the content out at the printable width so the measured size reflects
    // the real page, then scale so the whole week fits ONE page (never split).
    await page.setViewport({ width: availW, height: availH });
    await page.setContent(html, { waitUntil: 'networkidle0' });

    const contentH = await page.evaluate(() => document.documentElement.scrollHeight);
    const contentW = await page.evaluate(() => document.documentElement.scrollWidth);
    // Shrink as far as needed so the WHOLE week fits one page (font gets small
    // rather than any gym/row being dropped) — a multi-page training plan is not
    // useful. No floor: fitting everything beats truncating.
    const scale = Math.min(1, availH / contentH, availW / contentW);

    // PDF — single A4 page, given scale, no auto-pagination overflow.
    const pdfPath = path.join(OUTPUT_DIR, filename);
    await page.pdf({
      path: pdfPath,
      format: 'A4',
      landscape,
      printBackground: true,
      scale,
      pageRanges: '1',
      margin: { top: `${MARGIN}px`, bottom: `${MARGIN}px`, left: `${MARGIN}px`, right: `${MARGIN}px` },
    });

    // PNG — same one-page framing: scale the body, shoot the page rectangle.
    await page.evaluate((s) => {
      document.body.style.transformOrigin = 'top left';
      document.body.style.transform = `scale(${s})`;
    }, scale);
    await page.setViewport({ width: pageW, height: pageH });
    const pngFilename = filename.replace(/\.pdf$/, '.png');
    const pngPath = path.join(OUTPUT_DIR, pngFilename);
    await page.screenshot({ path: pngPath, type: 'png', clip: { x: 0, y: 0, width: pageW, height: pageH } });
  } finally {
    await browser.close();
  }
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  if (req.method === 'POST' && req.url === '/generate') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const { html, filename, landscape } = JSON.parse(body);

        if (!html || !filename) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ success: false, error: 'Missing html or filename' }));
          return;
        }

        await generateFiles(html, filename, landscape === true);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true }));
      } catch (err) {
        console.error('Generation error:', err);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: err.message }));
      }
    });
    return;
  }

  res.writeHead(404);
  res.end();
});

server.listen(PORT, () => {
  console.log(`pdf-worker listening on port ${PORT}`);
});
