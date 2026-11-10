import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import type { NestExpressApplication } from '@nestjs/platform-express';
import type { NextFunction, Request, Response } from 'express';
import { AppModule } from './app.module.js';
import { Settings } from './common/settings.js';
import { HttpErrorsFilter } from './common/http-errors.filter.js';

async function bootstrap(): Promise<void> {
  const app = await NestFactory.create<NestExpressApplication>(AppModule, {
    rawBody: true,
  });
  app.disable('x-powered-by');
  app.useBodyParser('json', { limit: '64kb' });
  app.use((_req: Request, res: Response, next: NextFunction) => {
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    next();
  });
  app.useGlobalFilters(new HttpErrorsFilter());
  app.enableCors({
    origin: app.get(Settings).frontendUrl,
    methods: ['GET', 'PUT', 'PATCH', 'OPTIONS'],
    allowedHeaders: ['Authorization', 'Content-Type', 'Accept'],
    credentials: false,
  });
  app.enableShutdownHooks();
  await app.listen(app.get(Settings).port, '0.0.0.0');
}
void bootstrap().catch(() => {
  console.error('Notifications API could not start.');
  process.exitCode = 1;
});
