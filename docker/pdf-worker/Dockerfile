FROM ghcr.io/puppeteer/puppeteer:latest

WORKDIR /app/frontend

# worker.js only imports node builtins + puppeteer — the frontend package.json no
# longer declares puppeteer (dropped in 92763d3), so `npm ci` here would NOT install
# it. Reuse the base image's install instead: its version always matches the Chrome
# cached in the same image, where an `npm install puppeteer` could drift from that
# cache and find no browser. Being a symlink, it resolves at runtime against the
# running container, so the pdf_worker_node_modules volume can never pin a stale copy.
RUN mkdir -p node_modules \
    && ln -s /home/pptruser/node_modules/puppeteer node_modules/puppeteer

COPY frontend/worker.js ./worker.js

CMD ["node", "worker.js"]
