import { ForbiddenException, Injectable, Optional } from '@nestjs/common';
import { Mailer } from '@nestjs/mail';
import { randomUUID } from 'node:crypto';
import { Database } from '../../infrastructure/database/database.service.js';
import { IdentityClient } from '../../integrations/identity/identity.client.js';
import { notificationDestination } from '../../common/notification-destination.js';
import { Settings } from '../../common/settings.js';
import {
  channels,
  type Preference,
} from '../preferences/preferences.service.js';

interface DeliveryRow {
  id: string;
  notification_id: string;
  attempts: number;
}
interface MailNotice {
  company_id: string;
  recipient_id: string;
  eligibility_permission: string;
  kind: string;
  title: string;
  message: string;
  destination: string;
  priority: string;
  category: string;
}
@Injectable()
export class DeliveriesWorker {
  constructor(
    private readonly database: Database,
    private readonly identity: IdentityClient,
    private readonly settings: Settings,
    @Optional() private readonly mailer?: Mailer,
  ) {}
  async processOne(): Promise<boolean> {
    if (!this.settings.mailEnabled || !this.mailer) return false;
    const token = randomUUID();
    const rows = await this.database.query<DeliveryRow>(
      `WITH due AS (SELECT id FROM notification_deliveries WHERE status IN ('PENDING','PROCESSING')
      AND (attempts<8 OR status='PROCESSING') AND next_attempt_at<=now() AND (locked_until IS NULL OR locked_until<now())
      ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
      UPDATE notification_deliveries d SET status='PROCESSING',attempts=d.attempts+1,lock_token=$1,locked_until=now()+interval '5 minutes'
      FROM due WHERE d.id=due.id RETURNING d.id,d.notification_id,d.attempts`,
      [token],
    );
    const delivery = rows[0];
    if (!delivery) return false;
    try {
      if (delivery.attempts > 8)
        throw Object.assign(new Error('Exhausted delivery lease'), {
          permanent: true,
        });
      const notices = await this.database.query<MailNotice>(
        'SELECT company_id::text,recipient_id::text,eligibility_permission,kind,title,message,destination,priority,category FROM notifications WHERE id=$1',
        [delivery.notification_id],
      );
      const notice = notices[0];
      if (!notice)
        throw Object.assign(new Error('Notification missing'), {
          permanent: true,
        });
      const candidates = await this.identity.recipients(
        notice.company_id,
        notice.eligibility_permission,
      );
      const recipient = candidates.find(
        (candidate) =>
          candidate.userId === notice.recipient_id &&
          candidate.companyId === notice.company_id,
      );
      const preferences = await this.database.query<Preference>(
        'SELECT recipient_id::text,kind,inbox_enabled,email_enabled FROM notification_preferences WHERE company_id=$1 AND recipient_id=$2',
        [notice.company_id, notice.recipient_id],
      );
      if (
        !recipient ||
        !channels(preferences, notice.kind, notice.priority, notice.category)
          .email
      ) {
        await this.finish(delivery.id, token, 'CANCELLED');
        return true;
      }
      const leases = await this.database.query<{ id: string }>(
        "SELECT id FROM notification_deliveries WHERE id=$1 AND lock_token=$2 AND status='PROCESSING' AND locked_until>now()",
        [delivery.id, token],
      );
      if (!leases.length) return true;
      const result = await this.mailer.send({
        to: recipient.email,
        subject: notice.title,
        text:
          notice.message +
          '\n\n' +
          new URL(
            notificationDestination(notice.destination),
            this.settings.frontendUrl,
          ).href,
        retry: false,
        signal: AbortSignal.timeout(30000),
        idempotencyKey: delivery.id,
      });
      await this.database.query(
        "UPDATE notification_deliveries SET status='PROVIDER_ACCEPTED',provider_message_id=$3,accepted_at=now(),lock_token=NULL,locked_until=NULL,last_error_code=NULL WHERE id=$1 AND lock_token=$2",
        [delivery.id, token, result.providerMessageId ?? result.messageId],
      );
    } catch (error: unknown) {
      if (error instanceof ForbiddenException) {
        await this.finish(delivery.id, token, 'CANCELLED');
      } else {
        const permanent =
          typeof error === 'object' &&
          error !== null &&
          'permanent' in error &&
          error.permanent === true;
        await this.database.query(
          `UPDATE notification_deliveries SET status=$3,next_attempt_at=now()+($4::int*interval '1 second'),lock_token=NULL,locked_until=NULL,last_error_code='DELIVERY_UNAVAILABLE'
          WHERE id=$1 AND lock_token=$2`,
          [
            delivery.id,
            token,
            permanent || delivery.attempts >= 8 ? 'FAILED' : 'PENDING',
            Math.min(3600, 30 * 2 ** Math.min(delivery.attempts, 7)),
          ],
        );
      }
    }
    return true;
  }
  private async finish(
    id: string,
    token: string,
    status: string,
  ): Promise<void> {
    await this.database.query(
      'UPDATE notification_deliveries SET status=$3,lock_token=NULL,locked_until=NULL WHERE id=$1 AND lock_token=$2',
      [id, token, status],
    );
  }
}
