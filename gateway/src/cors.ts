import type { NestExpressApplication } from '@nestjs/platform-express';

export function configureCors(app: NestExpressApplication): void {
  const origins = (process.env.CORS_ORIGINS ?? 'http://localhost:3000')
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean);
  for (const origin of origins) {
    const parsed = new URL(origin);
    if (
      !['http:', 'https:'].includes(parsed.protocol) ||
      parsed.origin !== origin
    ) {
      throw new Error(
        'CORS_ORIGINS must contain exact HTTP(S) origins without paths or credentials.',
      );
    }
  }
  app.enableCors({
    origin: [...new Set(origins)],
    methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    allowedHeaders: [
      'Authorization',
      'Content-Type',
      'Accept',
      'Idempotency-Key',
    ],
    exposedHeaders: ['X-Request-Id'],
    credentials: false,
    maxAge: 600,
  });
}
