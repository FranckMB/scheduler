FROM node:22-alpine AS tooling

WORKDIR /app/frontend

COPY frontend/package*.json frontend/.npmrc ./
RUN npm ci --legacy-peer-deps

COPY frontend/ ./
COPY docker/frontend/ /app/docker/frontend/
COPY docker/nginx/default.conf /app/docker/nginx/default.conf

FROM tooling AS build

RUN npm run build

FROM nginx:1.27-alpine

COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/frontend/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY docker/frontend/csp.conf /etc/nginx/snippets/csp.conf
COPY --from=build /app/frontend/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost/ || exit 1
