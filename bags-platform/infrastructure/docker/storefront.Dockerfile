FROM node:22-bookworm-slim AS build
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@10.34.4 --activate
COPY frontend/ ./
RUN pnpm install --frozen-lockfile
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_BACKOFFICE_URL
ARG R2_PUBLIC_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL NEXT_PUBLIC_BACKOFFICE_URL=$NEXT_PUBLIC_BACKOFFICE_URL R2_PUBLIC_URL=$R2_PUBLIC_URL NEXT_TELEMETRY_DISABLED=1 STOREFRONT_STANDALONE=1
RUN pnpm --filter @alfarid/storefront build

FROM node:22-bookworm-slim AS runtime
WORKDIR /app
ENV NODE_ENV=production HOSTNAME=0.0.0.0
COPY --from=build --chown=node:node /app/apps/storefront/.next/standalone ./
COPY --from=build --chown=node:node /app/apps/storefront/.next/static ./apps/storefront/.next/static
COPY --from=build --chown=node:node /app/apps/storefront/public ./apps/storefront/public
USER node
EXPOSE 3000
CMD ["node", "apps/storefront/server.js"]
