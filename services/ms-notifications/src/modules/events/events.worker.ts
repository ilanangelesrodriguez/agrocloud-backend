import { Injectable } from '@nestjs/common';
import { randomUUID } from 'node:crypto';
import { Database } from '../../infrastructure/database/database.service.js';
import {
  IdentityClient,
  type Recipient,
} from '../../integrations/identity/identity.client.js';
import { Settings } from '../../common/settings.js';
import {
  channels,
  type Preference,
} from '../preferences/preferences.service.js';
import { noticeFor, parseEvent, type Source } from './event-contract.js';

interface EventRow {
  source: Source;
  event_id: string;
  company_id: string;
  payload: unknown;
  attempts: number;
  lock_token: string;
}
interface Target extends Recipient {
  permission: string;
}
@Injectable()
export class EventsWorker {
  constructor(
    private readonly database: Database,
    private readonly identity: IdentityClient,
    private readonly settings: Settings,
  ) {}
  async processOne(): Promise<boolean> {
    const token = randomUUID();
    const rows = await this.database.query<EventRow>(
      `WITH due AS (SELECT source,event_id FROM processed_events
      WHERE status IN ('PENDING','PROCESSING') AND (attempts<8 OR status='PROCESSING') AND next_attempt_at<=now()
      AND (locked_until IS NULL OR locked_until<now()) ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
      UPDATE processed_events e SET status='PROCESSING',attempts=e.attempts+1,lock_token=$1,locked_until=now()+interval '5 minutes'
      FROM due WHERE e.source=due.source AND e.event_id=due.event_id RETURNING e.*`,
      [token],
    );
    const event = rows[0];
    if (!event) return false;
    try {
      if (event.attempts > 8) throw new Error('Exhausted event lease');
      const parsed = parseEvent(event.source, event.payload);
      const notice = noticeFor(event.source, parsed);
      const recipients = new Map<string, Target>();
      for (const permission of notice.permissions) {
        for (const recipient of await this.identity.recipients(
          parsed.companyId,
          permission,
        ))
          recipients.set(recipient.userId, { ...recipient, permission });
      }
      if (notice.explicitIds.length) {
        const explicitPermission =
          event.source === 'identity'
            ? ''
            : parsed.type.startsWith('incident.')
              ? 'incidencias.ver'
              : 'lotes.ver';
        const allowed = new Map(
          (
            await this.identity.recipients(parsed.companyId, explicitPermission)
          ).map((user) => [user.userId, user]),
        );
        if (event.source === 'identity') recipients.clear();
        for (const userId of notice.explicitIds) {
          const recipient = allowed.get(userId);
          if (recipient)
            recipients.set(userId, {
              ...recipient,
              permission: explicitPermission,
            });
        }
      }
      if (recipients.size > this.settings.maxRecipients)
        throw new Error('Recipient bound exceeded');
      await this.database.transaction(async (manager) => {
        const current = await manager.query<{ status: string }[]>(
          'SELECT status FROM processed_events WHERE source=$1 AND event_id=$2 AND lock_token=$3 FOR UPDATE',
          [event.source, event.event_id, token],
        );
        if (!current.length) return;
        const preferences = await manager.query<Preference[]>(
          'SELECT recipient_id::text,kind,inbox_enabled,email_enabled FROM notification_preferences WHERE company_id=$1 AND recipient_id=ANY($2::bigint[])',
          [parsed.companyId, [...recipients.keys()]],
        );
        const grouped = new Map<string, Preference[]>();
        for (const preference of preferences)
          grouped.set(preference.recipient_id, [
            ...(grouped.get(preference.recipient_id) ?? []),
            preference,
          ]);
        const batch = [...recipients.values()].flatMap((recipient) => {
          const selected = channels(
            grouped.get(recipient.userId) ?? [],
            notice.kind,
            notice.priority,
            notice.category,
          );
          return selected.inbox
            ? [
                {
                  id: randomUUID(),
                  company_id: parsed.companyId,
                  recipient_id: recipient.userId,
                  event_source: event.source,
                  event_id: event.event_id,
                  kind: notice.kind,
                  title: notice.title,
                  message: notice.message,
                  destination: notice.destination,
                  priority: notice.priority,
                  category: notice.category,
                  eligibility_permission: recipient.permission,
                  email: selected.email,
                  delivery_id: randomUUID(),
                },
              ]
            : [];
        });
        if (batch.length) {
          await manager.query(
            `INSERT INTO notifications(id,company_id,recipient_id,event_source,event_id,kind,title,message,destination,priority,category,eligibility_permission)
            SELECT id,company_id,recipient_id,event_source,event_id,kind,title,message,destination,priority,category,eligibility_permission
            FROM jsonb_to_recordset($1::jsonb) AS n(id uuid,company_id bigint,recipient_id bigint,event_source text,event_id text,kind text,title text,message text,destination text,priority text,category text,eligibility_permission text)
            ON CONFLICT (event_source,event_id,recipient_id) DO NOTHING`,
            [JSON.stringify(batch)],
          );
          await manager.query(
            `INSERT INTO notification_deliveries(id,notification_id)
            SELECT b.delivery_id,n.id FROM jsonb_to_recordset($1::jsonb) AS b(delivery_id uuid,id uuid,email boolean)
            JOIN notifications n ON n.id=b.id WHERE b.email ON CONFLICT (notification_id) DO NOTHING`,
            [JSON.stringify(batch)],
          );
        }
        await manager.query(
          "UPDATE processed_events SET status='COMPLETED',processed_at=now(),lock_token=NULL,locked_until=NULL,last_error_code=NULL WHERE source=$1 AND event_id=$2 AND lock_token=$3",
          [event.source, event.event_id, token],
        );
      });
    } catch (error: unknown) {
      const details = error && typeof error === 'object' ? error as Record<string, unknown> : {};
      const driver = details.driverError && typeof details.driverError === 'object'
        ? details.driverError as Record<string, unknown>
        : {};
      console.error('Notification event processing failed.', {
        type: error instanceof Error ? error.name : 'UnknownError',
        code: typeof driver.code === 'string' ? driver.code.slice(0, 20)
          : typeof details.code === 'string' ? details.code.slice(0, 20) : 'UNKNOWN',
        source: event.source,
      });
      try {
        await this.database.query(
          `UPDATE processed_events SET status=$4,next_attempt_at=now()+($5::int*interval '1 second'),lock_token=NULL,locked_until=NULL,last_error_code='PROCESSING_UNAVAILABLE'
          WHERE source=$1 AND event_id=$2 AND lock_token=$3`,
          [
            event.source,
            event.event_id,
            token,
            event.attempts >= 8 ? 'FAILED' : 'PENDING',
            Math.min(3600, 15 * 2 ** Math.min(event.attempts, 8)),
          ],
        );
      } catch (recoveryError: unknown) {
        const recovery = recoveryError && typeof recoveryError === 'object'
          ? recoveryError as Record<string, unknown>
          : {};
        const cause = recovery.driverError && typeof recovery.driverError === 'object'
          ? recovery.driverError as Record<string, unknown>
          : {};
        console.error('Notification event retry update failed.', {
          type: recoveryError instanceof Error ? recoveryError.name : 'UnknownError',
          code: typeof cause.code === 'string' ? cause.code.slice(0, 20)
            : typeof recovery.code === 'string' ? recovery.code.slice(0, 20) : 'UNKNOWN',
        });
        throw recoveryError;
      }
    }
    return true;
  }
}
