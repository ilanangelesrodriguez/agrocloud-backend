FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY gateway/package.json ./gateway/package.json
COPY services/ms-billing/package.json ./services/ms-billing/package.json
COPY services/ms-notifications/package.json ./services/ms-notifications/package.json
RUN npm ci --ignore-scripts --no-audit --no-fund
ARG WORKSPACE
COPY . .
RUN npm run build --workspace=${WORKSPACE}
RUN npm prune --omit=dev --ignore-scripts --no-audit --no-fund

FROM node:22-alpine
ENV NODE_ENV=production PORT=3000
WORKDIR /app
ARG WORKSPACE
COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/${WORKSPACE}/package.json ./${WORKSPACE}/package.json
COPY --from=build --chown=node:node /app/${WORKSPACE}/dist ./${WORKSPACE}/dist
WORKDIR /app/${WORKSPACE}
USER node
EXPOSE 3000
CMD ["node", "dist/main.js"]
