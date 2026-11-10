import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { setTimeout } from 'node:timers/promises';
import { WorkerModule } from './worker.module.js';
import { EventsWorker } from './modules/events/events.worker.js';
import { DeliveriesWorker } from './modules/deliveries/deliveries.worker.js';
import { Database } from './infrastructure/database/database.service.js';
import { Settings } from './common/settings.js';

async function main(): Promise<void> {
  const app = await NestFactory.createApplicationContext(WorkerModule, {
    logger: ['warn', 'error'],
  });
  const stop = new AbortController();
  const shutdown = () => stop.abort();
  process.once('SIGTERM', shutdown);
  process.once('SIGINT', shutdown);
  let lastCleanup = 0;
  try {
    const events = app.get(EventsWorker);
    const deliveries = app.get(DeliveriesWorker);
    while (!stop.signal.aborted) {
      let worked = false;
      try {
        worked = await events.processOne();
      } catch {
        console.error(
          'Event processing unavailable; persistent leases retain work.',
        );
      }
      if (!stop.signal.aborted) {
        try {
          worked = (await deliveries.processOne()) || worked;
        } catch {
          console.error(
            'Mail processing unavailable; persistent leases retain work.',
          );
        }
      }
      if (Date.now() - lastCleanup > 3600000) {
        try {
          await app
            .get(Database)
            .query(
              "DELETE FROM notification_rate_limits WHERE window_start<now()-interval '1 day'",
            );
          lastCleanup = Date.now();
        } catch {
          /* Retry cleanup in the next iteration. */
        }
      }
      if (!worked && !stop.signal.aborted)
        await setTimeout(app.get(Settings).workerInterval, undefined, {
          signal: stop.signal,
        });
    }
  } catch (error) {
    if (!stop.signal.aborted) throw error;
  } finally {
    process.removeListener('SIGTERM', shutdown);
    process.removeListener('SIGINT', shutdown);
    await app.close();
  }
}
void main().catch(() => {
  console.error('Notifications worker could not start.');
  process.exitCode = 1;
});
