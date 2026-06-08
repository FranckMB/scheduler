FROM ghcr.io/puppeteer/puppeteer:latest

WORKDIR /app

ENV NODE_ENV=development \
    PUPPETEER_SKIP_DOWNLOAD=true

EXPOSE 3000

CMD ["node", "-e", "console.log('pdf-worker ready'); setInterval(() => {}, 1000);"]
