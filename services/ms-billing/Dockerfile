FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY gateway/package.json ./gateway/package.json
COPY services/ms-billing/package.json ./services/ms-billing/package.json
COPY services/ms-notifications/package.json ./services/ms-notifications/package.json
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY services/ms-billing ./services/ms-billing
RUN npm run build --workspace=@agrocloud/ms-billing
RUN npm prune --omit=dev --ignore-scripts --no-audit --no-fund

FROM node:22-alpine
ENV NODE_ENV=production PORT=3000
WORKDIR /app
COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/services/ms-billing/package.json ./services/ms-billing/package.json
COPY --from=build --chown=node:node /app/services/ms-billing/dist ./services/ms-billing/dist
WORKDIR /app/services/ms-billing
USER node
EXPOSE 3000
CMD ["node", "dist/main.js"]
