import {
  BadRequestException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import type { Actor } from '../../integrations/identity/identity.client.js';
import { Database } from '../../infrastructure/database/database.service.js';
import { id, record, uuid } from '../../common/validation.js';

interface InboxRow {
  id: string;
  seq: string;
  kind: string;
  title: string;
  message: string;
  destination: string;
  priority: string;
  category: string;
  read_at: Date | null;
  created_at: Date;
}
@Injectable()
export class NotificationsService {
  constructor(private readonly database: Database) {}
  async list(actor: Actor, input: unknown) {
    const query = record(input, ['limit', 'cursor', 'unread']);
    if (
      query.limit !== undefined &&
      (typeof query.limit !== 'string' ||
        !/^[1-9][0-9]{0,2}$/.test(query.limit))
    )
      throw new BadRequestException('Invalid limit.');
    if (
      query.unread !== undefined &&
      (typeof query.unread !== 'string' ||
        !['true', 'false'].includes(query.unread))
    )
      throw new BadRequestException('Invalid unread filter.');
    const limit = query.limit === undefined ? 25 : Number(query.limit);
    if (
      !Number.isSafeInteger(limit) ||
      limit < 1 ||
      limit > 100 ||
      (query.unread !== undefined &&
        !['true', 'false'].includes(String(query.unread)))
    )
      throw new BadRequestException('Invalid pagination.');
    const cursor = query.cursor === undefined ? null : id(query.cursor);
    const unread = query.unread === 'true';
    const rows = await this.database.query<InboxRow>(
      `SELECT id,seq::text,kind,title,message,destination,priority,category,read_at,created_at
      FROM notifications WHERE company_id=$1 AND recipient_id=$2 AND ($3::bigint IS NULL OR seq<$3::bigint)
      AND (NOT $4::boolean OR read_at IS NULL) AND (category='security' OR eligibility_permission=ANY($6::varchar[])) ORDER BY seq DESC LIMIT $5`,
      [
        actor.companyId,
        actor.userId,
        cursor,
        unread,
        limit + 1,
        actor.permissions,
      ],
    );
    const hasMore = rows.length > limit;
    const selected = rows.slice(0, limit);
    return {
      data: selected.map(({ seq, ...item }) => ({ ...item, cursor: seq })),
      nextCursor: hasMore ? selected[selected.length - 1].seq : null,
    };
  }
  async unread(actor: Actor) {
    const rows = await this.database.query<{ count: string }>(
      `SELECT count(*)::text AS count FROM notifications WHERE company_id=$1 AND recipient_id=$2 AND read_at IS NULL AND (category='security' OR eligibility_permission=ANY($3::varchar[]))`,
      [actor.companyId, actor.userId, actor.permissions],
    );
    return { unreadCount: Number(rows[0].count) };
  }
  async markRead(actor: Actor, notificationId: string) {
    const rows = await this.database.query<{ id: string }>(
      `UPDATE notifications SET read_at=COALESCE(read_at,now()) WHERE id=$1 AND company_id=$2 AND recipient_id=$3 AND (category='security' OR eligibility_permission=ANY($4::varchar[])) RETURNING id`,
      [uuid(notificationId), actor.companyId, actor.userId, actor.permissions],
    );
    if (!rows.length) throw new NotFoundException('Notification not found.');
    return { read: true };
  }
  async markAllRead(actor: Actor) {
    const rows = await this.database.query<{ count: string }>(
      `WITH updated AS (UPDATE notifications SET read_at=now()
      WHERE company_id=$1 AND recipient_id=$2 AND read_at IS NULL AND (category='security' OR eligibility_permission=ANY($3::varchar[])) RETURNING id) SELECT count(*)::text AS count FROM updated`,
      [actor.companyId, actor.userId, actor.permissions],
    );
    return { updated: Number(rows[0].count) };
  }
}
