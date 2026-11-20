import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { InjectModel } from '@nestjs/mongoose';
import { createHmac, randomUUID } from 'node:crypto';
import type { Model } from 'mongoose';
import { OutboundEvent } from './schemas/outbox.schema.js';

@Injectable()
export class OutboxService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(OutboxService.name);

  private timer?: ReturnType<typeof setInterval>;

  constructor(
    @InjectModel(OutboundEvent.name)
    private readonly events: Model<OutboundEvent>,
    private readonly config: ConfigService,
  ) {}

  onModuleInit(): void {
    if (process.env.VERCEL === '1' || !this.config.get<boolean>('OUTBOX_TIMER_ENABLED', true))
      return;
    if (
      !this.config.get<string>('AGROCLOUD_EVENT_URL') &&
      !this.config.get<string>('NOTIFICATIONS_EVENT_URL')
    ) {
      this.logger.warn('AGROCLOUD_EVENT_URL vacío: los eventos permanecerán pendientes');
      return;
    }
    const delay = this.config.get<number>('OUTBOX_INTERVAL_MS', 30000);
    this.timer = setInterval(
      () =>
        void this.dispatchDue().catch(() => this.logger.warn('Reintento de notificaciones falló')),
      delay,
    );
    this.timer.unref();
  }

  onModuleDestroy(): void {
    if (this.timer) clearInterval(this.timer);
  }

  async enqueuePayment(order: {
    externalReference: string;
    companyId: number;
    amountCents: number;
    currency: string;
    kind: string;
    culqiChargeId?: string;
  }): Promise<void> {
    const eventKey = `payment.succeeded:${order.externalReference}`;
    for (const target of ['identity', 'notifications'] as const) {
      const deliveryKey = target === 'identity' ? eventKey : 'notifications:' + eventKey;
      try {
        await this.events.updateOne(
          { eventKey: deliveryKey },
          {
            $setOnInsert: {
              eventKey: deliveryKey,
              target,
              eventType: 'payment.succeeded',
              status: 'PENDING',
              attempts: 0,
              nextAttemptAt: new Date(),
              payload: {
                event_id: eventKey,
                type: 'payment.succeeded',
                order_reference: order.externalReference,
                company_id: order.companyId,
                amount_cents: order.amountCents,
                currency: order.currency,
                kind: order.kind,
                provider_charge_id: order.culqiChargeId,
              },
            },
          },
          { upsert: true },
        );
      } catch (error: unknown) {
        if ((error as { code?: number }).code !== 11000) throw error;
      }
    }
  }

  async dispatchDue(limit = 20): Promise<{ attempted: number }> {
    if (
      !this.config.get<string>('AGROCLOUD_EVENT_URL') &&
      !this.config.get<string>('NOTIFICATIONS_EVENT_URL')
    )
      return { attempted: 0 };
    const due = await this.events
      .find({
        status: 'PENDING',
        nextAttemptAt: { $lte: new Date() },
        $or: [{ lockedUntil: { $exists: false } }, { lockedUntil: { $lte: new Date() } }],
      })
      .sort({ createdAt: 1 })
      .limit(limit);

    for (let offset = 0; offset < due.length; offset += 4) {
      await Promise.all(
        due.slice(offset, offset + 4).map((event) => this.dispatchOne(event.eventKey)),
      );
    }
    return { attempted: due.length };
  }

  private async identityToken(audience: string): Promise<string> {
    const url = new URL(
      'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity',
    );
    url.searchParams.set('audience', audience);
    const response = await fetch(url, {
      headers: { 'Metadata-Flavor': 'Google' },
      redirect: 'error',
      signal: AbortSignal.timeout(3000),
    });
    if (!response.ok) {
      await response.body?.cancel();
      throw new Error('Cloud Run authentication for Identity is unavailable');
    }
    const token = (await response.text()).trim();
    if (!/^[\w-]+\.[\w-]+\.[\w-]+$/.test(token)) {
      throw new Error('Cloud Run returned an invalid Identity token');
    }
    return token;
  }

  private async dispatchOne(eventKey: string): Promise<void> {
    const lockToken = randomUUID();
    const event = await this.events.findOneAndUpdate(
      {
        eventKey,
        status: 'PENDING',
        nextAttemptAt: { $lte: new Date() },
        $or: [{ lockedUntil: { $exists: false } }, { lockedUntil: { $lte: new Date() } }],
      },
      { $set: { lockToken, lockedUntil: new Date(Date.now() + 60000) } },
      { new: true },
    );
    if (!event) return;
    const body = JSON.stringify(event.payload);
    try {
      const notifications = event.target === 'notifications';
      const url = this.config.get<string>(
        notifications ? 'NOTIFICATIONS_EVENT_URL' : 'AGROCLOUD_EVENT_URL',
      );
      if (!url) throw new Error('Event receiver is not configured');
      const secret = this.config.getOrThrow<string>(
        notifications ? 'BILLING_EVENT_SECRET' : 'AGROCLOUD_EVENT_SECRET',
      );
      if (secret.length < 32) throw new Error('Invalid event signing secret');
      const signature = createHmac('sha256', secret).update(body).digest('hex');
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'X-AgroCloud-Signature': 'sha256=' + signature,
        'X-Event-Id': String(event.payload.event_id),
      };
      const audience = this.config.get<string>(
        notifications ? 'NOTIFICATIONS_CLOUD_RUN_AUDIENCE' : 'IDENTITY_CLOUD_RUN_AUDIENCE',
      );
      if (audience) {
        if (new URL(url).protocol !== 'https:') throw new Error('Identity IAM requires HTTPS');
        headers['X-Serverless-Authorization'] = 'Bearer ' + (await this.identityToken(audience));
      }
      const response = await fetch(url, {
        method: 'POST',
        signal: AbortSignal.timeout(10000),
        redirect: 'error',
        headers,
        body,
      });
      await response.body?.cancel();
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      await this.events.updateOne(
        { eventKey, status: 'PENDING', lockToken },
        {
          $unset: { lockedUntil: 1, lockToken: 1 },
          $set: {
            status: 'DELIVERED',
            deliveredAt: new Date(),
            lastError: null,
          },
          $inc: { attempts: 1 },
        },
      );
    } catch (error: unknown) {
      const attempts = event.attempts + 1;
      const delay = Math.min(3600000, 15000 * 2 ** Math.min(attempts, 8));
      await this.events.updateOne(
        { eventKey, status: 'PENDING', lockToken },
        {
          $unset: { lockedUntil: 1, lockToken: 1 },
          $set: {
            lastError: String(error).slice(0, 160),
            nextAttemptAt: new Date(Date.now() + delay),
          },
          $inc: { attempts: 1 },
        },
      );
      this.logger.warn(`No se entregó ${eventKey}; se reintentará`);
    }
  }
}
