# syntax=docker/dockerfile:1.7
FROM node:22-bookworm-slim AS build
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@10.34.4 --activate
COPY frontend/ ./
RUN pnpm install --frozen-lockfile
ARG APP
ARG VITE_API_URL
ARG VITE_WEB_URL
ARG VITE_CSP_CONNECT_ORIGINS
ARG VITE_SENTRY_DSN
ARG VITE_SENTRY_ENVIRONMENT
ARG VITE_SENTRY_RELEASE
ARG VITE_SENTRY_TRACES_SAMPLE_RATE
ARG SENTRY_ORG
ARG SENTRY_PROJECT
ARG VITE_POS_SOURCE
ENV VITE_API_URL=$VITE_API_URL VITE_WEB_URL=$VITE_WEB_URL \
    VITE_SENTRY_DSN=$VITE_SENTRY_DSN VITE_SENTRY_ENVIRONMENT=$VITE_SENTRY_ENVIRONMENT \
    VITE_SENTRY_RELEASE=$VITE_SENTRY_RELEASE VITE_SENTRY_TRACES_SAMPLE_RATE=$VITE_SENTRY_TRACES_SAMPLE_RATE \
    SENTRY_ORG=$SENTRY_ORG SENTRY_PROJECT=$SENTRY_PROJECT
RUN --mount=type=secret,id=sentry_auth_token,required=false \
    if [ "$APP" = "backoffice" ] && [ -f /run/secrets/sentry_auth_token ]; then \
      SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token)" pnpm --filter @alfarid/${APP} build; \
    else \
      pnpm --filter @alfarid/${APP} build; \
    fi
COPY infrastructure/docker/pos-security.mjs /tmp/pos-security.mjs
RUN if [ "$APP" = pos ]; then node /tmp/pos-security.mjs; fi \
    && cp -r apps/${APP}/dist /output

FROM nginx:alpine
COPY --from=build /output /usr/share/nginx/html
COPY infrastructure/docker/static.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
