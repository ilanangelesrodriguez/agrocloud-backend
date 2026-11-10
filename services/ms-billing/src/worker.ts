import 'reflect-metadata';
import { setTimeout } from 'node:timers/promises';
import { NestFactory } from '@nestjs/core';
import { ConfigService } from '@nestjs/config';
import { AppModule } from './app.module.js';
import { OutboxService } from './infrastructure/outbox/outbox.service.js';

async function main(): Promise<void> {
  // This entry point owns dispatch; disable the HTTP process's legacy timer.
  process.env.OUTBOX_TIMER_ENABLED = 'false';
  const app = await NestFactory.createApplicationContext(AppModule);
  const stop = new AbortController();
  const shutdown = () => stop.abort();
  process.once('SIGINT', shutdown);
  process.once('SIGTERM', shutdown);
  try {
    const config = app.get(ConfigService);
    if (!config.get<string>('AGROCLOUD_EVENT_URL')) {
      throw new Error('Outbox worker requires AGROCLOUD_EVENT_URL');
    }
    const outbox = app.get(OutboxService);
    while (!stop.signal.aborted) {
      try { await outbox.dispatchDue(); }
      catch { console.error('Outbox dispatch failed; retrying after interval'); }
      await setTimeout(config.get<number>('OUTBOX_INTERVAL_MS', 30000), undefined, { signal: stop.signal });
    }
  } catch (error) {
    if (!stop.signal.aborted) throw error;
  } finally {
    process.removeListener('SIGINT', shutdown);
    process.removeListener('SIGTERM', shutdown);
    await app.close();
  }
}

void main().catch((error: unknown) => {
  console.error('Outbox worker failed:', error);
  process.exitCode = 1;
});
