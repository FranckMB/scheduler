import http from 'http';
import fs from 'fs';
import path from 'path';
import puppeteer from 'puppeteer';
import { startHeartbeat } from './worker-heartbeat.js';

const PORT = 3000;
const OUTPUT_DIR = '/app/backend/public/exports';

// A4 in CSS px at 96dpi. Landscape swaps the two. ~24px of margin each way.
const A4 = { w: 794, h: 1123 };
const MARGIN = 24;

async function generateFiles(html, filename, landscape, multiSection, pngSection) {
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
    // the real page.
    await page.setViewport({ width: availW, height: availH });
    await page.setContent(html, { waitUntil: 'networkidle0' });

    const geom = { pageW, pageH, availW, availH };
    if (multiSection) {
      await renderMultiSection(page, filename, landscape, geom, pngSection);
    } else {
      await renderSinglePage(page, filename, landscape, geom);
    }
  } finally {
    await browser.close();
  }
}

// Historical one-page export (unchanged): the whole document is scaled so the
// WHOLE week fits ONE A4 page, and the PNG frames the same single page. Every
// single-section call takes this path and gets exactly the file it got before.
async function renderSinglePage(page, filename, landscape, { pageW, pageH, availW, availH }) {
  const { contentH, contentW } = await page.evaluate(() => ({
    contentH: document.documentElement.scrollHeight,
    contentW: document.documentElement.scrollWidth,
  }));
  // Shrink as far as needed so the WHOLE week fits one page (font gets small
  // rather than any gym/row being dropped) — a multi-page training plan is not
  // useful. No floor: fitting everything beats truncating.
  const scale = Math.min(1, availH / contentH, availW / contentW);

  // PDF — single A4 page, given scale, no auto-pagination overflow.
  // SEC-14 : le filename vient de la requête — basename() interdit la
  // traversée (../../) même si l'appelant interne était compromis.
  // nosemgrep: javascript.lang.security.audit.path-traversal.path-join-resolve-traversal.path-join-resolve-traversal -- basename() interdit la traversée
  const pdfPath = path.join(OUTPUT_DIR, path.basename(filename));
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
  const pngFilename = path.basename(filename).replace(/\.pdf$/, '.png');
  const pngPath = path.join(OUTPUT_DIR, pngFilename);
  await page.screenshot({ path: pngPath, type: 'png', clip: { x: 0, y: 0, width: pageW, height: pageH } });
}

// Multi-section export : section 1 (`.page-grid`) is the manager grid — fitted to
// page 1 exactly like the single-page path — and section 2 (`.page-matrix`, forced
// onto a new page by CSS `break-before: page`) is the team × day matrix, which
// flows onto page(s) 2+ at natural size.
//
// The PDF is IDENTICAL either way. Only the PNG changes: `pngSection` says which
// section the image is shot from — absent/'grid' = page 1 (the manager grid, the
// historical behaviour), 'matrix' = the team × day matrix (P3-20, "la vue par club"
// en image). The image is one landscape page in both cases.
async function renderMultiSection(page, filename, landscape, { pageW, pageH, availW, availH }, pngSection) {
  // Measure the GRID section AND the header above it : page 1 carries the document
  // header (club title bar) THEN the grid, and both must fit one page. Measuring the
  // grid alone let the header's height spill the grid's tail onto page 2.
  const m = await page.evaluate(() => {
    // The 24px print margins already inset the page; drop the body padding so the
    // measurement is against exactly one printable page.
    document.body.style.padding = '0';
    const el = document.querySelector('.page-grid');
    const rect = el ? el.getBoundingClientRect() : null;
    return {
      // gridTop = everything above the grid on page 1 (header + its margin).
      gridTop: rect ? rect.top : 0,
      gridH: el ? el.scrollHeight : 0,
      gridW: el ? el.scrollWidth : 0,
    };
  });
  const gridH = m.gridH || availH;
  const gridW = m.gridW || availW;
  // Fit the grid in the room LEFT under the header on one printable page. A small inset
  // keeps it clear of the page boundary so sub-pixel rounding never spills a sliver onto
  // page 2 (that spill was the failure mode of a transform+overflow box : `transform`
  // does NOT reflow, so `overflow: hidden` cannot clip across a print page break). `zoom`
  // DOES reflow, so the grid's box actually shrinks and the matrix's `break-before: page`
  // opens page 2.
  const FIT_INSET = 6;
  const usableH = availH - FIT_INSET - m.gridTop;
  // Print row heights round slightly differently from the screen measurement, and the
  // error accumulates over many rows; a small proportional margin keeps a tall grid off
  // the page-2 boundary whatever its row count (the same "font gets a bit smaller rather
  // than a row spills" trade-off the single-page path already makes).
  const SAFETY = 0.96;
  const scale = Math.min(1, (usableH / gridH) * SAFETY, availW / gridW);

  await page.evaluate((s) => {
    const el = document.querySelector('.page-grid');
    if (el) {
      el.style.zoom = String(s);
    }
  }, scale);

  // PDF — natural pagination (no pageRanges, no global scale) : the grid is already
  // fitted in page 1, the matrix flows onto the following page(s), its rank groups
  // kept whole by `break-inside: avoid`.
  // nosemgrep: javascript.lang.security.audit.path-traversal.path-join-resolve-traversal.path-join-resolve-traversal -- basename() interdit la traversée
  const pdfPath = path.join(OUTPUT_DIR, path.basename(filename));
  await page.pdf({
    path: pdfPath,
    format: 'A4',
    landscape,
    printBackground: true,
    margin: { top: `${MARGIN}px`, bottom: `${MARGIN}px`, left: `${MARGIN}px`, right: `${MARGIN}px` },
  });

  // PNG — one section only, chosen by `pngSection`. The PDF is already written, so the
  // DOM can be reshaped freely from here on.
  if (pngSection === 'matrix') {
    // "Par club" : hide the grid (and drop its zoom, which no longer means anything),
    // then fit the matrix in the room left under the header, same discipline as above.
    const mm = await page.evaluate(() => {
      const grid = document.querySelector('.page-grid');
      if (grid) { grid.style.zoom = ''; grid.style.display = 'none'; }
      const el = document.querySelector('.page-matrix');
      // `break-before: page` only speaks to print — on screen the section simply moves up.
      if (el) el.style.breakBefore = 'auto';
      const rect = el ? el.getBoundingClientRect() : null;
      return { top: rect ? rect.top : 0, h: el ? el.scrollHeight : 0, w: el ? el.scrollWidth : 0 };
    });
    const usableH = availH - FIT_INSET - mm.top;
    const matrixScale = Math.min(1, (usableH / (mm.h || availH)) * SAFETY, availW / (mm.w || availW));
    await page.evaluate((s) => {
      const el = document.querySelector('.page-matrix');
      if (el) el.style.zoom = String(s);
    }, matrixScale);
  } else {
    await page.evaluate(() => {
      const m = document.querySelector('.page-matrix');
      if (m) m.style.display = 'none';
    });
  }
  await page.setViewport({ width: pageW, height: pageH });
  const pngFilename = path.basename(filename).replace(/\.pdf$/, '.png');
  const pngPath = path.join(OUTPUT_DIR, pngFilename);
  await page.screenshot({ path: pngPath, type: 'png', clip: { x: 0, y: 0, width: pageW, height: pageH } });
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
        const { html, filename, landscape, multiSection, pngSection } = JSON.parse(body);

        if (!html || !filename) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ success: false, error: 'Missing html or filename' }));
          return;
        }

        await generateFiles(html, filename, landscape === true, multiSection === true, pngSection === 'matrix' ? 'matrix' : 'grid');

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

function startWorker() {
  // Heartbeat admin — module builtins-only (aucune dépendance npm dans ce conteneur).
  startHeartbeat();

  server.listen(PORT, () => {
    console.log(`pdf-worker listening on port ${PORT}`);
  });
}

if (process.argv[1]?.endsWith('worker.js')) {
  startWorker();
}
