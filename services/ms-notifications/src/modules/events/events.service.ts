import {
  ConflictException,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
import { Database } from '../../infrastructure/database/database.service.js';
import { Settings } from '../../common/settings.js';
import { parseEvent, type Source } from './event-contract.js';

@Injectable()
export class EventsService {
  constructor(
    private readonly database: Database,
    private readonly settings: Settings,
  ) {}
  async accept(
    source: Source,
    body: unknown,
    raw: Buffer | undefined,
    signature: string | undefined,
    headerId: string | undefined,
  ) {
    const secret =
      source === 'agricultural'
        ? this.settings.agriculturalSecret
        : source === 'identity'
          ? this.settings.identityEventSecret
          : this.settings.billingEventSecret;
    if (
      secret.length < 32 ||
      !raw ||
      raw.length > 65536 ||
      !signature ||
      !/^sha256=[a-f0-9]{64}$/.test(signature)
    )
      throw new UnauthorizedException('Invalid event signature.');
    const expected = createHmac('sha256', secret).update(raw).digest();
    if (!timingSafeEqual(expected, Buffer.from(signature.slice(7), 'hex')))
      throw new UnauthorizedException('Invalid event signature.');
    const event = parseEvent(source, body);
    if (headerId !== event.eventId)
      throw new UnauthorizedException('Event identifiers do not match.');
    const hash = createHash('sha256').update(raw).digest('hex');
    const inserted = await this.database.query<{ event_id: string }>(
      `INSERT INTO processed_events(source,event_id,company_id,payload_hash,payload)
      VALUES ($1,$2,$3,$4,$5::jsonb) ON CONFLICT (source,event_id) DO NOTHING RETURNING event_id`,
      [
        source,
        event.eventId,
        event.companyId,
        hash,
        JSON.stringify(event.payload),
      ],
    );
    const rows = await this.database.query<{
      payload_hash: string;
      company_id: string;
    }>(
      'SELECT payload_hash,company_id::text FROM processed_events WHERE source=$1 AND event_id=$2',
      [source, event.eventId],
    );
    if (
      !rows[0] ||
      rows[0].payload_hash !== hash ||
      rows[0].company_id !== event.companyId
    )
      throw new ConflictException('Event ID reused with another payload.');
    return {
      accepted: true,
      duplicate: inserted.length === 0,
      eventId: event.eventId,
    };
  }
}
