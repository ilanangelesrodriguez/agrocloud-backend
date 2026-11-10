# Build context: monorepo root. This recipe is not executed by the migration script.
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY gateway/package.json ./gateway/package.json
COPY services/ms-billing/package.json ./services/ms-billing/package.json
COPY services/ms-notifications/package.json ./services/ms-notifications/package.json
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY services/ms-notifications ./services/ms-notifications
RUN npm run build --workspace=@agrocloud/ms-notifications
RUN npm prune --omit=dev --ignore-scripts --no-audit --no-fund

FROM node:22-alpine
ENV NODE_ENV=production PORT=3000
WORKDIR /app
COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/services/ms-notifications/package.json ./services/ms-notifications/package.json
COPY --from=build --chown=node:node /app/services/ms-notifications/dist ./services/ms-notifications/dist
COPY --from=build --chown=node:node /app/services/ms-notifications/scripts ./services/ms-notifications/scripts
COPY --from=build --chown=node:node /app/services/ms-notifications/migrations ./services/ms-notifications/migrations
COPY --from=build --chown=node:node /app/services/ms-notifications/docs.html /app/services/ms-notifications/openapi.yaml ./services/ms-notifications/
WORKDIR /app/services/ms-notifications
USER node
EXPOSE 3000
CMD ["node", "dist/main.js"]
