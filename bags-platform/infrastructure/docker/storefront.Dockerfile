FROM node:22-bookworm-slim AS build
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@10.34.4 --activate
COPY frontend/ ./
RUN pnpm install --frozen-lockfile
ARG NEXT_PUBLIC_API_URL
ARG R2_PUBLIC_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL R2_PUBLIC_URL=$R2_PUBLIC_URL NEXT_TELEMETRY_DISABLED=1
RUN pnpm --filter @alfarid/storefront build

FROM build AS runtime
ENV NODE_ENV=production HOSTNAME=0.0.0.0
USER node
EXPOSE 3000
CMD ["pnpm", "--filter", "@alfarid/storefront", "start"]
