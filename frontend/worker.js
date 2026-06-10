const http = require('http');
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const PORT = 3000;
const OUTPUT_DIR = '/app/backend/public/exports';

if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

async function generatePdf(html, outputPath) {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle0' });
    await page.pdf({
        path: outputPath,
        format: 'A4',
        printBackground: true,
        margin: { top: '20px', right: '20px', bottom: '20px', left: '20px' },
    });
    await browser.close();
}

const server = http.createServer(async (req, res) => {
    if (req.method !== 'POST' || req.url !== '/generate') {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Not found' }));
        return;
    }

    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
        try {
            const { html, filename } = JSON.parse(body);
            if (!html || !filename) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: 'Missing html or filename' }));
                return;
            }

            const outputPath = path.join(OUTPUT_DIR, filename);
            await generatePdf(html, outputPath);

            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: true, path: outputPath }));
        } catch (error) {
            console.error('PDF generation error:', error);
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: error.message }));
        }
    });
});

server.listen(PORT, () => {
    console.log(`PDF worker listening on port ${PORT}`);
});
