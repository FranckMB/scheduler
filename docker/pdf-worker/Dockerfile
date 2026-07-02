FROM ghcr.io/puppeteer/puppeteer:latest

WORKDIR /app/frontend

ENV NODE_ENV=development \
    PUPPETEER_SKIP_DOWNLOAD=true \
    NODE_PATH=/app/frontend/node_modules

COPY frontend/package*.json ./

RUN npm ci --legacy-peer-deps

COPY frontend ./

CMD ["node", "worker.js"]