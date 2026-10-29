FROM node:22-bookworm-slim AS build
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@10.34.4 --activate
COPY frontend/ ./
RUN pnpm install --frozen-lockfile
ARG APP
ARG VITE_API_URL
ARG VITE_WEB_URL
ENV VITE_API_URL=$VITE_API_URL VITE_WEB_URL=$VITE_WEB_URL
RUN pnpm --filter @alfarid/${APP} build
COPY infrastructure/docker/pos-security.mjs /tmp/pos-security.mjs
RUN if [ "$APP" = pos ]; then node /tmp/pos-security.mjs; fi \
    && cp -r apps/${APP}/dist /output

FROM nginx:alpine
COPY --from=build /output /usr/share/nginx/html
COPY infrastructure/docker/static.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
