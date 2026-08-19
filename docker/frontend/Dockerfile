FROM node:24-alpine AS tooling

WORKDIR /app/frontend

COPY frontend/package*.json frontend/.npmrc ./
RUN npm ci --legacy-peer-deps

COPY frontend/ ./
COPY docker/frontend/ /app/docker/frontend/
COPY docker/nginx/default.conf /app/docker/nginx/default.conf

FROM tooling AS build

RUN npm run build

FROM nginx:1.27-alpine AS runtime
# SEC A19 — même règle que nginx-prod backend : ramener les paquets Alpine au
# niveau des dépôts (le gate Trivy vérifie derrière).
RUN apk upgrade --no-cache

COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/frontend/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY docker/frontend/csp.conf /etc/nginx/snippets/csp.conf
COPY --from=build /app/frontend/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost/ || exit 1

# prod — même runtime, avec `nginx.prod.conf`.
# ⚠ Cet étage se justifiait par une conf prod « sans le proxy /engine/ de debug ». Ce proxy a
# été retiré du DEV aussi le 2026-07-31 : depuis, les deux confs sont IDENTIQUES aux
# commentaires près (vérifié au diff le 2026-08-19, audit DOC-32). Le dédoublement ne porte
# donc plus aucune différence — fusion à instruire (P4-118), pas au détour d'une PR de doc.
FROM runtime AS prod
COPY docker/frontend/nginx.prod.conf /etc/nginx/conf.d/default.conf

# Étage par défaut (le dernier) = runtime dev, pour que `docker build` nu et le compose dev
# prennent `nginx.conf`. La CI construit la prod via --target prod.
FROM runtime
