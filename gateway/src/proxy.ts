import { request as httpRequest, type IncomingHttpHeaders } from 'node:http';
import { request as httpsRequest } from 'node:https';
import type { NestExpressApplication } from '@nestjs/platform-express';
import type { Request, Response } from 'express';

const hopHeaders = [
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
];

function endToEnd(headers: IncomingHttpHeaders): IncomingHttpHeaders {
  const result = { ...headers };
  for (const name of [
    ...hopHeaders,
    ...(headers.connection ?? '').split(','),
  ]) {
    delete result[name.trim().toLowerCase()];
  }
  return result;
}

function proxyError(
  response: Response,
  statusCode: number,
  code: string,
  message: string,
) {
  response.status(statusCode).setHeader('Cache-Control', 'no-store');
  response.json({
    success: false,
    statusCode,
    error: { code, message },
    meta: { requestId: response.getHeader('X-Request-Id') },
  });
}

// Fixed routes only; credentials remain the caller's responsibility.
export function configureProxy(app: NestExpressApplication): void {
  const routes = [
    ['identity', 'IDENTITY_SERVICE_URL', 'http://127.0.0.1:8001'],
    ['agricultural', 'AGRICULTURAL_SERVICE_URL', 'http://127.0.0.1:8002'],
    ['billing', 'BILLING_SERVICE_URL', 'http://127.0.0.1:4001'],
    ['notifications', 'NOTIFICATIONS_SERVICE_URL', 'http://127.0.0.1:4002'],
    ['ai', 'AI_SERVICE_URL', 'http://127.0.0.1:8003'],
  ];
  for (const [service, variable, fallback] of routes) {
    const base = new URL(process.env[variable] ?? fallback);
    if (
      !['http:', 'https:'].includes(base.protocol) ||
      base.username ||
      base.password ||
      base.pathname !== '/' ||
      base.search ||
      base.hash
    ) {
      throw new Error(`Invalid service origin: ${variable}`);
    }
    app.use(`/api/v1/${service}`, async (req: Request, res: Response) => {
      // Do not expose backend worker/admin endpoints through the public gateway.
      const path = req.url.split('?')[0];
      let decoded: string;
      try {
        decoded = decodeURIComponent(path);
      } catch {
        proxyError(res, 400, 'BAD_REQUEST', 'Invalid path');
        return;
      }
      const segments = decoded.split('/').filter(Boolean);
      if (
        decoded.includes('%') ||
        decoded.includes('\\') ||
        /[?#;]/.test(decoded) ||
        Array.from(decoded).some(
          (character) => character.charCodeAt(0) <= 32,
        ) ||
        segments.some((segment) => segment === '.' || segment === '..') ||
        segments[0]?.toLowerCase() === 'internal'
      ) {
        proxyError(res, 404, 'RESOURCE_NOT_FOUND', 'Not found');
        return;
      }
      const normalized = '/' + segments.join('/');
      const query = req.url.slice(path.length);
      let upstreamPath = `/api/v1${req.url.startsWith('/') ? req.url : '/' + req.url}`;
      if (
        ['GET', 'HEAD'].includes(req.method) &&
        ['/health', '/ready'].includes(normalized)
      ) {
        const healthPrefix = ['identity', 'agricultural'].includes(service)
          ? '/api/v1'
          : '';
        upstreamPath = healthPrefix + normalized + query;
      } else if (service === 'notifications') {
        // Flat public inbox routes; the older service-prefixed routes remain valid.
        if (
          normalized === '/' ||
          normalized === '/unread-count' ||
          normalized === '/read-all' ||
          /^\/[0-9a-f-]{36}\/read$/i.test(normalized)
        ) {
          upstreamPath =
            '/api/v1/notifications' +
            (normalized === '/' ? '' : normalized) +
            query;
        } else if (normalized === '/preferences') {
          upstreamPath = '/api/v1/notification-preferences' + query;
        }
      }
      const headers = endToEnd(req.headers);
      delete headers.host;
      const requestId = String(res.getHeader('X-Request-Id'));
      headers['x-request-id'] = requestId;
      // Never trust identity claims or forwarding headers supplied by clients.
      for (const name of Object.keys(headers)) {
        if (
          name.startsWith('x-forwarded-') ||
          name === 'forwarded' ||
          name === 'x-user-id' ||
          name === 'x-company-id' ||
          name === 'x-tenant-id' ||
          name === 'x-service-key' ||
          name === 'x-notifications-operator-key'
        ) {
          delete headers[name];
        }
      }
      // Nunca reenviar credenciales de infraestructura aportadas por clientes.
      delete headers['x-serverless-authorization'];

      if (process.env.K_SERVICE) {
        try {
          const metadataUrl = new URL(
            'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity'
          );
          metadataUrl.searchParams.set('audience', base.origin);

          const metadata = await fetch(metadataUrl, {
            headers: { 'Metadata-Flavor': 'Google' },
            signal: AbortSignal.timeout(3000),
          });

          if (!metadata.ok) throw new Error('Metadata unavailable');

          const token = (await metadata.text()).trim();
          if (token.split('.').length !== 3) {
            throw new Error('Invalid identity token');
          }

          headers['x-serverless-authorization'] = `Bearer ${token}`;
        } catch {
          if (!res.headersSent && !res.destroyed) {
            proxyError(res, 503, 'IDENTITY_TOKEN_UNAVAILABLE', 'Service authentication unavailable');
          }
          return;
        }
      }

      if (req.aborted || res.destroyed) return;

      const send = base.protocol === 'https:' ? httpsRequest : httpRequest;
      const upstream = send(
        {
          protocol: base.protocol,
          hostname: base.hostname,
          port: base.port,
          method: req.method,
          path: upstreamPath,
          headers,
        },
        (reply) => {
          if (res.destroyed || res.writableEnded) {
            reply.destroy();
            return;
          }
          res.writeHead(reply.statusCode ?? 502, {
            ...endToEnd(reply.headers),
            'x-request-id': requestId,
          });
          reply.on('error', () => res.destroy());
          reply.pipe(res);
        },
      );
      const deadline = setTimeout(() => {
        if (!res.headersSent)
          proxyError(res, 504, 'DEPENDENCY_TIMEOUT', 'Upstream timeout');
        else res.destroy();
        upstream.destroy();
      }, 120_000);
      upstream.on('error', () => {
        if (!res.headersSent && !res.destroyed)
          proxyError(res, 502, 'DEPENDENCY_UNAVAILABLE', 'Service unavailable');
        else res.destroy();
      });
      res.on('close', () => {
        clearTimeout(deadline);
        upstream.destroy();
      });
      req.on('aborted', () => upstream.destroy());
      req.on('error', () => upstream.destroy());
      req.pipe(upstream);
    });
  }
}
