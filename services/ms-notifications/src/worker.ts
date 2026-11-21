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
  let lastHealthCheck = 0;
  try {
    const events = app.get(EventsWorker);
    const deliveries = app.get(DeliveriesWorker);
    while (!stop.signal.aborted) {
      let worked = false;
      if (Date.now() - lastHealthCheck > 300000) {
        lastHealthCheck = Date.now();
        try {
          const health = await app
            .get(Database)
            .query<{ queue: string; failed: string; stalled: string }>(
              `SELECT 'events' AS queue,count(*) FILTER (WHERE status='FAILED')::text AS failed,
            count(*) FILTER (WHERE status IN ('PENDING','PROCESSING') AND created_at<now()-interval '5 minutes')::text AS stalled FROM processed_events
            UNION ALL SELECT 'deliveries',count(*) FILTER (WHERE status='FAILED')::text,
            count(*) FILTER (WHERE status IN ('PENDING','PROCESSING') AND created_at<now()-interval '15 minutes')::text FROM notification_deliveries`,
            );
          for (const queue of health) {
            if (queue.queue === 'deliveries' && !app.get(Settings).mailEnabled)
              continue;
            if (Number(queue.failed) > 0 || Number(queue.stalled) > 0)
              console.error(
                'Notification queue requires operator attention.',
                queue,
              );
          }
        } catch {
          console.error('Notification queue health check unavailable.');
        }
      }
      try {
        worked = await events.processOne();
      } catch (error: unknown) {
        const details =
          error && typeof error === 'object'
            ? (error as Record<string, unknown>)
            : {};
        const driver =
          details.driverError && typeof details.driverError === 'object'
            ? (details.driverError as Record<string, unknown>)
            : {};
        console.error(
          'Event processing unavailable; persistent leases retain work.',
          {
            type: error instanceof Error ? error.name : 'UnknownError',
            code:
              typeof driver.code === 'string'
                ? driver.code.slice(0, 20)
                : typeof details.code === 'string'
                  ? details.code.slice(0, 20)
                  : 'UNKNOWN',
          },
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
