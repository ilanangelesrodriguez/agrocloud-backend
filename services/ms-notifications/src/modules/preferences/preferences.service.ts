import { BadRequestException, Injectable } from '@nestjs/common';
import type { EntityManager } from 'typeorm';
import type { Actor } from '../../integrations/identity/identity.client.js';
import { Database } from '../../infrastructure/database/database.service.js';
import { boolean, record, strings } from '../../common/validation.js';

export const OPTIONAL_KINDS = [
  'LOTE_REGISTRADO',
  'LOTE_RECIBIDO',
  'INSPECCION_ASIGNADA',
  'INSPECCION_VALIDADA',
  'DICTAMEN_APROBADO',
] as const;
export interface Preference {
  recipient_id: string;
  kind: string;
  inbox_enabled: boolean;
  email_enabled: boolean;
}
export function channels(
  preferences: Preference[],
  kind: string,
  priority: string,
  category: string,
): { inbox: boolean; email: boolean } {
  if (category === 'security') return { inbox: true, email: true };
  const optional =
    priority === 'normal' &&
    (OPTIONAL_KINDS as readonly string[]).includes(kind);
  const muted =
    optional &&
    preferences.some((row) => row.kind === kind && !row.inbox_enabled);
  const email = preferences.some(
    (row) => row.kind === '*' && row.email_enabled,
  );
  return { inbox: !muted, email: !muted && email };
}
@Injectable()
export class PreferencesService {
  constructor(private readonly database: Database) {}
  async read(actor: Actor) {
    const rows = await this.database.query<Preference>(
      'SELECT recipient_id::text,kind,inbox_enabled,email_enabled FROM notification_preferences WHERE company_id=$1 AND recipient_id=$2',
      [actor.companyId, actor.userId],
    );
    return {
      mutedKinds: rows
        .filter((row) => !row.inbox_enabled)
        .map((row) => row.kind),
      emailEnabled: rows.some((row) => row.kind === '*' && row.email_enabled),
      optionalKinds: OPTIONAL_KINDS,
      criticalSecurityCannotBeMuted: true,
    };
  }
  async replace(actor: Actor, body: unknown) {
    const data = record(body, ['mutedKinds', 'emailEnabled']);
    const muted = strings(data.mutedKinds, OPTIONAL_KINDS.length);
    const email = boolean(data.emailEnabled);
    if (
      new Set(muted).size !== muted.length ||
      muted.some(
        (kind) => !(OPTIONAL_KINDS as readonly string[]).includes(kind),
      )
    )
      throw new BadRequestException('Only listed optional kinds can be muted.');
    await this.database.transaction(async (manager: EntityManager) => {
      await manager.query(
        'SELECT pg_advisory_xact_lock(hashtextextended($1,0))',
        [`preferences:${actor.companyId}:${actor.userId}`],
      );
      await manager.query(
        'DELETE FROM notification_preferences WHERE company_id=$1 AND recipient_id=$2',
        [actor.companyId, actor.userId],
      );
      await manager.query(
        "INSERT INTO notification_preferences(company_id,recipient_id,kind,inbox_enabled,email_enabled) VALUES ($1,$2,'*',true,$3)",
        [actor.companyId, actor.userId, email],
      );
      for (const kind of muted)
        await manager.query(
          'INSERT INTO notification_preferences(company_id,recipient_id,kind,inbox_enabled,email_enabled) VALUES ($1,$2,$3,false,false)',
          [actor.companyId, actor.userId, kind],
        );
    });
    return this.read(actor);
  }
}
