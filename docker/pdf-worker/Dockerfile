FROM ghcr.io/puppeteer/puppeteer:latest

WORKDIR /app/frontend

ENV NODE_ENV=development \
    PUPPETEER_SKIP_DOWNLOAD=true \
    NODE_PATH=/app/frontend/node_modules

COPY frontend/package*.json ./

RUN npm ci

COPY frontend ./

CMD ["node", "worker.js"]