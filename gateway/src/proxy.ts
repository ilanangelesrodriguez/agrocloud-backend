import { request as httpRequest, type IncomingHttpHeaders } from 'node:http';
import { request as httpsRequest } from 'node:https';
import type { NestExpressApplication } from '@nestjs/platform-express';
import type { Request, Response } from 'express';

const hopHeaders = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
  'te', 'trailer', 'transfer-encoding', 'upgrade'];

function endToEnd(headers: IncomingHttpHeaders): IncomingHttpHeaders {
  const result = { ...headers };
  for (const name of [...hopHeaders, ...(headers.connection ?? '').split(',')]) {
    delete result[name.trim().toLowerCase()];
  }
  return result;
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
    if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password ||
        base.pathname !== '/' || base.search || base.hash) {
      throw new Error(`Invalid service origin: ${variable}`);
    }
    app.use(`/api/v1/${service}`, (req: Request, res: Response) => {
      // Do not expose backend worker/admin endpoints through the public gateway.
      const path = req.url.split('?')[0];
      let decoded: string;
      try { decoded = decodeURIComponent(path); }
      catch { res.status(400).json({ error: 'Invalid path' }); return; }
      if (decoded.includes('..') || decoded.includes('\\') ||
          /^\/+internal(?:\/|$)/i.test(decoded)) {
        res.status(404).json({ error: 'Not found' }); return;
      }
      const headers = endToEnd(req.headers);
      delete headers.host;
      // Never trust identity claims or forwarding headers supplied by clients.
      for (const name of Object.keys(headers)) {
        if (name.startsWith('x-forwarded-') || name === 'forwarded' ||
            name === 'x-user-id' || name === 'x-company-id' || name === 'x-tenant-id') {
          delete headers[name];
        }
      }
      const send = base.protocol === 'https:' ? httpsRequest : httpRequest;
      const upstream = send({
        protocol: base.protocol, hostname: base.hostname, port: base.port,
        method: req.method, path: `/api/v1${req.url.startsWith('/') ? req.url : '/' + req.url}`,
        headers,
      }, (reply) => {
        if (res.destroyed || res.writableEnded) { reply.destroy(); return; }
        res.writeHead(reply.statusCode ?? 502, endToEnd(reply.headers));
        reply.on('error', () => res.destroy());
        reply.pipe(res);
      });
      const deadline = setTimeout(() => {
        if (!res.headersSent) res.status(504).json({ error: 'Upstream timeout' });
        else res.destroy();
        upstream.destroy();
      }, 120_000);
      upstream.on('error', () => {
        if (!res.headersSent && !res.destroyed) res.status(502).json({ error: 'Service unavailable' });
        else res.destroy();
      });
      res.on('close', () => { clearTimeout(deadline); upstream.destroy(); });
      req.on('aborted', () => upstream.destroy());
      req.on('error', () => upstream.destroy());
      req.pipe(upstream);
    });
  }
}
