FROM ghcr.io/puppeteer/puppeteer:latest

WORKDIR /app/frontend

# worker.js imports node builtins + puppeteer, et c'est le SEUL consommateur.
#
# `frontend/package.json` ne déclare DÉLIBÉRÉMENT pas puppeteer : seule la paire
# puppeteer↔Chrome de l'image de base est garantie cohérente. Un paquet installé
# par npm dériverait de ce cache et le worker ne trouverait aucun navigateur.
# D'où le lien symbolique — et, étant un lien, il se résout à l'exécution contre
# le conteneur courant, donc le volume pdf_worker_node_modules ne peut jamais
# figer une copie périmée.
#
# Le déclarer côté npm n'apporterait rien et coûterait : ni `tsc` (limité à
# `src`) ni ESLint (limité aux `.ts/.tsx`) ne voient ce fichier, aucun test ne
# l'importe, et son postinstall téléchargerait un Chrome complet à chaque
# `npm ci` — deux fois par run de CI — pour un binaire jamais utilisé.
RUN mkdir -p node_modules \
    && ln -s /home/pptruser/node_modules/puppeteer node_modules/puppeteer

COPY frontend/worker.js ./worker.js
# Heartbeat admin (builtins uniquement — AUCUNE dépendance npm, voir worker-heartbeat.js).
COPY frontend/worker-heartbeat.js ./worker-heartbeat.js

CMD ["node", "worker.js"]
