import http from 'http';
import fs from 'fs';
import path from 'path';
import puppeteer from 'puppeteer';

const PORT = 3000;
const OUTPUT_DIR = '/app/backend/public/exports';

async function generateFiles(html, filename) {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });

  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  try {
    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle0' });

    // PDF
    const pdfPath = path.join(OUTPUT_DIR, filename);
    await page.pdf({ path: pdfPath, format: 'A4', printBackground: true });

    // PNG — page 1 at A4 dimensions (794×1123 px at 96dpi)
    await page.setViewport({ width: 794, height: 1123 });
    const pngFilename = filename.replace(/\.pdf$/, '.png');
    const pngPath = path.join(OUTPUT_DIR, pngFilename);
    await page.screenshot({ path: pngPath, type: 'png', fullPage: false });
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
        const { html, filename } = JSON.parse(body);

        if (!html || !filename) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ success: false, error: 'Missing html or filename' }));
          return;
        }

        await generateFiles(html, filename);

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
