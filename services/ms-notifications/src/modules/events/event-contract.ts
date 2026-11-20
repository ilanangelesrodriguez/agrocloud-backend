import { BadRequestException } from '@nestjs/common';
import {
  id,
  numericId,
  record,
  strings,
  text,
  uuid,
} from '../../common/validation.js';

export type Source = 'agricultural' | 'identity' | 'billing';
export interface DomainEvent {
  eventId: string;
  companyId: string;
  type: string;
  data: Record<string, unknown>;
  payload: Record<string, unknown>;
}
export interface Notice {
  kind: string;
  title: string;
  message: string;
  destination: string;
  priority: 'normal' | 'high' | 'critical';
  category: 'operational' | 'security' | 'billing';
  permissions: string[];
  explicitIds: string[];
}
const agriculturalTypes = [
  'lot.created',
  'lot.cancelled',
  'lot.received',
  'inspection.validated',
  'quality.decision.issued',
  'incident.opened',
  'incident.updated',
  'evidence.analysis_failed',
];
const eventFields: Record<string, string[]> = {
  'lot.created': ['lotId'],
  'lot.cancelled': ['lotId'],
  'lot.received': ['lotId', 'receptionId', 'recipientPermissions'],
  'inspection.validated': ['lotId', 'inspectionId', 'recipientPermissions'],
  'quality.decision.issued': [
    'lotId',
    'inspectionId',
    'decisionId',
    'decision',
    'reinspection',
    'recipientPermissions',
  ],
  'incident.opened': [
    'lotId',
    'incidentId',
    'severity',
    'recipientIds',
    'recipientPermissions',
  ],
  'incident.updated': ['lotId', 'incidentId', 'status', 'recipientPermissions'],
  'evidence.analysis_failed': ['lotId', 'evidenceId', 'recipientIds'],
};
export function parseEvent(source: Source, body: unknown): DomainEvent {
  if (source === 'billing') {
    const payload = record(body, [
      'event_id',
      'type',
      'order_reference',
      'company_id',
      'amount_cents',
      'currency',
      'kind',
      'provider_charge_id',
    ]);
    const reference = text(payload.order_reference, 36);
    if (
      payload.type !== 'payment.succeeded' ||
      payload.event_id !== 'payment.succeeded:' + reference ||
      !/^[A-Za-z0-9_-]{8,36}$/.test(reference) ||
      !Number.isSafeInteger(payload.amount_cents) ||
      Number(payload.amount_cents) < 100 ||
      Number(payload.amount_cents) > 999900 ||
      payload.currency !== 'PEN' ||
      !['PLAN', 'RENEWAL', 'USER_ADDON', 'AI_ADDON', 'LOT_ADDON'].includes(
        String(payload.kind),
      )
    )
      throw new BadRequestException('Invalid billing event.');
    if (payload.provider_charge_id !== undefined)
      text(payload.provider_charge_id, 100);
    return {
      eventId: text(payload.event_id, 150),
      companyId: String(numericId(payload.company_id)),
      type: 'payment.succeeded',
      data: payload,
      payload,
    };
  }
  const payload = record(body, [
    'eventId',
    'type',
    'version',
    'companyId',
    'occurredAt',
    'data',
  ]);
  const eventId = uuid(payload.eventId);
  const companyId = String(numericId(payload.companyId));
  const type = text(payload.type, 100);
  const occurredAt = text(payload.occurredAt, 50);
  if (
    payload.version !== 1 ||
    !/(Z|[+-]\d{2}:\d{2})$/.test(occurredAt) ||
    !Number.isFinite(Date.parse(occurredAt))
  )
    throw new BadRequestException('Invalid event version or timestamp.');
  if (source === 'identity') {
    if (type !== 'account.security_notice')
      throw new BadRequestException('Unsupported Identity event.');
    const data = record(payload.data, ['recipientIds', 'code']);
    if (
      ![
        'SECURITY_NOTICE',
        'USER_ACCESS_CHANGED',
        'SUBSCRIPTION_UPDATED',
      ].includes(String(data.code))
    )
      throw new BadRequestException('Unsupported security notice.');
    const ids = data.recipientIds;
    if (!Array.isArray(ids) || !ids.length || ids.length > 10000)
      throw new BadRequestException('Explicit recipients required.');
    ids.forEach(numericId);
    return { eventId, companyId, type, data, payload };
  }
  if (!agriculturalTypes.includes(type))
    throw new BadRequestException('Unsupported agricultural event.');
  const data = record(payload.data, eventFields[type]);
  numericId(data.lotId);
  for (const key of [
    'receptionId',
    'inspectionId',
    'decisionId',
    'incidentId',
    'evidenceId',
  ]) {
    if (eventFields[type].includes(key)) numericId(data[key]);
  }
  if (data.recipientPermissions !== undefined)
    strings(data.recipientPermissions);
  if (data.recipientIds !== undefined) {
    if (!Array.isArray(data.recipientIds) || data.recipientIds.length > 10000)
      throw new BadRequestException('Invalid recipient list.');
    data.recipientIds.forEach(numericId);
  }
  if (
    ['evidence.analysis_failed', 'incident.opened'].includes(type) &&
    (!Array.isArray(data.recipientIds) || !data.recipientIds.length)
  )
    throw new BadRequestException('Explicit recipients required.');
  if (
    type === 'quality.decision.issued' &&
    (!['APROBADO', 'OBSERVADO', 'RECHAZADO'].includes(String(data.decision)) ||
      typeof data.reinspection !== 'boolean' ||
      (data.decision !== 'OBSERVADO' && data.reinspection))
  )
    throw new BadRequestException('Invalid quality decision.');
  if (
    type === 'incident.opened' &&
    !['BAJA', 'MEDIA', 'ALTA', 'CRITICA'].includes(String(data.severity))
  )
    throw new BadRequestException('Invalid severity.');
  if (
    type === 'incident.updated' &&
    !['ABIERTA', 'EN_ATENCION', 'RESUELTA', 'CERRADA'].includes(
      String(data.status),
    )
  )
    throw new BadRequestException('Invalid incident status.');
  return { eventId, companyId, type, data, payload };
}
export function noticeFor(source: Source, event: DomainEvent): Notice {
  const base: Notice = {
    kind: '',
    title: '',
    message: '',
    destination: '/notifications',
    priority: 'normal',
    category: 'operational',
    permissions: [],
    explicitIds: [],
  };
  if (source === 'identity')
    return {
      ...base,
      kind: String(event.data.code),
      title:
        event.data.code === 'SUBSCRIPTION_UPDATED'
          ? 'Suscripción actualizada'
          : 'Seguridad de tu cuenta AgroCloud',
      message:
        event.data.code === 'SUBSCRIPTION_UPDATED'
          ? 'Se actualizaron el plan o los cupos de tu empresa.'
          : 'Se registró un cambio de seguridad o acceso en tu cuenta. Revisa tu cuenta o contacta al administrador.',
      priority: 'critical',
      category: 'security',
      destination:
        event.data.code === 'SUBSCRIPTION_UPDATED'
          ? '/settings/subscription'
          : '/settings/profile',
      permissions: [],
      explicitIds: (event.data.recipientIds as number[]).map(id),
    };
  if (source === 'billing')
    return {
      ...base,
      kind: 'PAGO_CONFIRMADO',
      title: 'Pago confirmado',
      message:
        'Se confirmó el pago de la orden ' + event.data.order_reference + '.',
      destination: '/settings/subscription',
      category: 'billing',
      priority: 'high',
      permissions: ['suscripciones.ver'],
    };
  const lot = String(event.data.lotId);
  const common = {
    ...base,
    destination: '/lots/' + lot,
    message: 'Se actualizó el expediente del lote #' + lot + '.',
  };
  switch (event.type) {
    case 'lot.created':
      return {
        ...common,
        kind: 'LOTE_REGISTRADO',
        destination: '/lots/' + lot + '/reception',
        title: 'Nuevo lote pendiente de recepción',
        permissions: ['recepciones.crear'],
      };
    case 'lot.cancelled':
      return {
        ...common,
        kind: 'LOTE_ANULADO',
        title: 'Lote anulado',
        priority: 'high',
        permissions: ['lotes.ver'],
      };
    case 'lot.received':
      return {
        ...common,
        kind: 'LOTE_RECIBIDO',
        destination: '/lots/' + lot + '/sampling',
        title: 'Lote disponible para muestreo',
        permissions: ['muestreos.crear'],
      };
    case 'inspection.validated':
      return {
        ...common,
        kind: 'INSPECCION_VALIDADA',
        destination: '/inspections/' + String(event.data.inspectionId),
        title: 'Inspección pendiente de dictamen',
        priority: 'high',
        permissions: ['calidad.clasificar'],
      };
    case 'quality.decision.issued': {
      const decision = String(event.data.decision);
      return {
        ...common,
        kind:
          decision === 'OBSERVADO'
            ? event.data.reinspection
              ? 'REINSPECCION_SOLICITADA'
              : 'CORRECCION_SOLICITADA'
            : 'DICTAMEN_' + decision,
        title:
          decision === 'APROBADO'
            ? 'Lote aprobado para producción'
            : decision === 'RECHAZADO'
              ? 'Lote rechazado por calidad'
              : 'Inspección requiere atención',
        priority: decision === 'APROBADO' ? 'normal' : 'high',
        permissions:
          decision === 'OBSERVADO'
            ? ['inspecciones.capturar', 'calidad.clasificar']
            : ['lotes.ver'],
      };
    }
    case 'incident.opened':
      return {
        ...common,
        kind: 'INCIDENCIA_ASIGNADA',
        title: 'Incidencia operativa abierta',
        priority: event.data.severity === 'CRITICA' ? 'critical' : 'high',
        permissions: ['calidad.clasificar'],
        explicitIds: ((event.data.recipientIds ?? []) as number[]).map(id),
      };
    case 'incident.updated':
      return {
        ...common,
        kind: 'INCIDENCIA_SEGUIMIENTO',
        title: 'Seguimiento de incidencia: ' + String(event.data.status),
        priority: 'high',
        permissions: ['calidad.clasificar'],
      };
    case 'evidence.analysis_failed':
      return {
        ...common,
        kind: 'IA_ERROR',
        title: 'Error en análisis de imagen',
        priority: 'high',
        permissions: [],
        explicitIds: (event.data.recipientIds as number[]).map(id),
      };
    default:
      throw new BadRequestException('Unsupported event.');
  }
}
