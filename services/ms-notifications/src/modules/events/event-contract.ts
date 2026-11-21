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
  'sampling.created',
  'lot.retained',
  'lot.released',
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
  'sampling.created': ['lotId', 'samplingId'],
  'lot.retained': ['lotId'],
  'lot.released': ['lotId'],
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
      'attempt_id',
    ]);
    const reference = text(payload.order_reference, 36);
    if (
      ![
        'payment.succeeded',
        'payment.failed',
        'payment.reconciliation_required',
      ].includes(String(payload.type)) ||
      payload.event_id !==
        (payload.type === 'payment.succeeded'
          ? 'payment.succeeded:' + reference
          : String(payload.type) +
            ':' +
            reference +
            ':' +
            text(payload.attempt_id, 24)) ||
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
    if (
      payload.type !== 'payment.succeeded' &&
      !/^[a-f0-9]{24}$/.test(String(payload.attempt_id))
    )
      throw new BadRequestException('Invalid payment attempt.');
    return {
      eventId: text(payload.event_id, 150),
      companyId: String(numericId(payload.company_id)),
      type: String(payload.type),
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
    if (
      ![
        'account.security_notice',
        'subscription.notice',
        'account.activity_notice',
      ].includes(type)
    )
      throw new BadRequestException('Unsupported Identity event.');
    const data = record(payload.data, [
      'recipientIds',
      'code',
      'orderReference',
      'planName',
      'expiresAt',
      'amountCents',
      'currency',
      'purchaseKind',
      'quantity',
      'resource',
      'threshold',
      'consumed',
      'limit',
      'daysRemaining',
    ]);
    if (
      ![
        'SECURITY_NOTICE',
        'USER_ACCESS_CHANGED',
        'SUBSCRIPTION_UPDATED',
        'SUBSCRIPTION_EXPIRING',
        'SUBSCRIPTION_EXPIRED',
        'QUOTA_THRESHOLD',
        'USER_INVITED',
        'COMPANY_APPROVED',
      ].includes(String(data.code))
    )
      throw new BadRequestException('Unsupported Identity notice.');
    const ids = data.recipientIds;
    if (!Array.isArray(ids) || !ids.length || ids.length > 10000)
      throw new BadRequestException('Explicit recipients required.');
    ids.forEach(numericId);
    const business = [
      'SUBSCRIPTION_UPDATED',
      'SUBSCRIPTION_EXPIRING',
      'SUBSCRIPTION_EXPIRED',
      'QUOTA_THRESHOLD',
    ];
    if (
      (type === 'subscription.notice' &&
        !business.includes(String(data.code))) ||
      (type === 'account.activity_notice' &&
        !['USER_INVITED', 'COMPANY_APPROVED'].includes(String(data.code))) ||
      (type === 'account.security_notice' &&
        ![
          'SECURITY_NOTICE',
          'USER_ACCESS_CHANGED',
          'SUBSCRIPTION_UPDATED',
        ].includes(String(data.code)))
    )
      throw new BadRequestException('Event type and notice code do not match.');
    if (type === 'subscription.notice') {
      const expiryValue = text(data.expiresAt, 50);
      if (
        !/(Z|[+-]\d{2}:\d{2})$/.test(expiryValue) ||
        !Number.isFinite(Date.parse(expiryValue))
      )
        throw new BadRequestException('Invalid subscription expiry.');
      if (data.code !== 'QUOTA_THRESHOLD') {
        text(data.planName, 150);
      }
      if (data.code === 'SUBSCRIPTION_UPDATED') {
        if (
          !/^[A-Za-z0-9_-]{8,36}$/.test(text(data.orderReference, 36)) ||
          !['PLAN', 'RENEWAL', 'USER_ADDON', 'LOT_ADDON', 'AI_ADDON'].includes(
            String(data.purchaseKind),
          ) ||
          !Number.isSafeInteger(data.amountCents) ||
          Number(data.amountCents) < 100 ||
          Number(data.amountCents) > 999900 ||
          data.currency !== 'PEN'
        )
          throw new BadRequestException('Invalid subscription purchase.');
        if (
          String(data.purchaseKind).endsWith('_ADDON') &&
          (!Number.isSafeInteger(data.quantity) ||
            Number(data.quantity) < 1 ||
            Number(data.quantity) > 1000000 ||
            !['usuarios', 'lotes', 'analisis_ia'].includes(
              String(data.resource),
            ))
        )
          throw new BadRequestException('Invalid additional quota.');
      }
      if (
        data.code === 'SUBSCRIPTION_EXPIRING' &&
        (!Number.isSafeInteger(data.daysRemaining) ||
          ![7, 3, 1].includes(Number(data.daysRemaining)))
      )
        throw new BadRequestException('Invalid expiry interval.');
      if (
        data.code === 'QUOTA_THRESHOLD' &&
        (!['usuarios', 'lotes', 'analisis_ia'].includes(
          String(data.resource),
        ) ||
          !Number.isSafeInteger(data.threshold) ||
          ![80, 100].includes(Number(data.threshold)) ||
          !Number.isSafeInteger(data.consumed) ||
          Number(data.consumed) < 0 ||
          !Number.isSafeInteger(data.limit) ||
          Number(data.limit) < 1 ||
          Number(data.consumed) <
            (Number(data.limit) * Number(data.threshold)) / 100)
      )
        throw new BadRequestException('Invalid quota threshold.');
    }
    return { eventId, companyId, type, data, payload };
  }
  if (!agriculturalTypes.includes(type))
    throw new BadRequestException('Unsupported agricultural event.');
  const data = record(payload.data, eventFields[type]);
  numericId(data.lotId);
  for (const key of [
    'samplingId',
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
  if (
    source === 'identity' &&
    [
      'SUBSCRIPTION_UPDATED',
      'SUBSCRIPTION_EXPIRING',
      'SUBSCRIPTION_EXPIRED',
      'QUOTA_THRESHOLD',
    ].includes(String(event.data.code))
  ) {
    const data = event.data;
    const resource = (
      {
        usuarios: 'usuarios',
        lotes: 'lotes',
        analisis_ia: 'análisis de IA',
      } as Record<string, string>
    )[String(data.resource)];
    const expiry = data.expiresAt
      ? new Intl.DateTimeFormat('es-PE', {
          dateStyle: 'long',
          timeZone: 'America/Lima',
        }).format(new Date(String(data.expiresAt)))
      : '';
    const amount = data.amountCents
      ? new Intl.NumberFormat('es-PE', {
          style: 'currency',
          currency: 'PEN',
        }).format(Number(data.amountCents) / 100)
      : '';
    let title = 'Suscripción actualizada';
    let message = 'Se actualizaron el plan o los cupos de tu empresa.';
    if (data.code === 'SUBSCRIPTION_UPDATED' && data.purchaseKind) {
      const addon = String(data.purchaseKind).endsWith('_ADDON');
      title = addon
        ? 'Cupos adicionales disponibles'
        : data.purchaseKind === 'RENEWAL'
          ? 'Tu plan ha sido renovado'
          : 'Tu plan ha sido activado';
      message = addon
        ? 'Se agregaron ' +
          data.quantity +
          ' cupos de ' +
          resource +
          '. Pago confirmado: ' +
          amount +
          '. Orden ' +
          data.orderReference +
          '.'
        : 'Plan ' +
          data.planName +
          ' disponible. Pago: ' +
          amount +
          '. Vencimiento: ' +
          expiry +
          '. Orden ' +
          data.orderReference +
          '.';
    } else if (data.code === 'SUBSCRIPTION_EXPIRING') {
      title =
        'Tu suscripción vence en ' +
        data.daysRemaining +
        (data.daysRemaining === 1 ? ' día' : ' días');
      message =
        'El plan ' +
        data.planName +
        ' vence el ' +
        expiry +
        '. Revisa las opciones de renovación para conservar tu acceso.';
    } else if (data.code === 'SUBSCRIPTION_EXPIRED') {
      title = 'Tu suscripción ha vencido';
      message =
        'El plan ' +
        data.planName +
        ' venció el ' +
        expiry +
        '. Revisa las opciones para renovar.';
    } else if (data.code === 'QUOTA_THRESHOLD') {
      title =
        data.threshold === 100
          ? 'Cupo de ' + resource + ' agotado'
          : 'Has alcanzado el 80 % del cupo de ' + resource;
      message =
        'Uso actual: ' +
        data.consumed +
        ' de ' +
        data.limit +
        '. Revisa tu suscripción o los complementos disponibles.';
    }
    return {
      ...base,
      kind: String(data.code),
      title,
      message,
      destination: '/settings/subscription',
      priority: 'high',
      category: 'billing',
      explicitIds: (data.recipientIds as number[]).map(id),
    };
  }
  if (
    source === 'identity' &&
    ['USER_INVITED', 'COMPANY_APPROVED'].includes(String(event.data.code))
  )
    return {
      ...base,
      kind: String(event.data.code),
      title:
        event.data.code === 'USER_INVITED'
          ? 'Invitación de usuario registrada'
          : 'Tu empresa fue aprobada',
      message:
        event.data.code === 'USER_INVITED'
          ? 'Se registró una invitación y se programó el correo de activación. Puedes consultar su estado en la gestión de usuarios.'
          : 'La empresa fue aprobada y tu cuenta está activada. Ya puedes ingresar a AgroCloud.',
      destination:
        event.data.code === 'USER_INVITED'
          ? '/settings/users'
          : '/settings/subscription',
      explicitIds: (event.data.recipientIds as number[]).map(id),
    };
  if (source === 'identity')
    return {
      ...base,
      kind: String(event.data.code),
      title: 'Seguridad de tu cuenta AgroCloud',
      message:
        'Se registró un cambio de seguridad o acceso en tu cuenta. Revisa tu cuenta o contacta al administrador.',
      priority: 'critical',
      category: 'security',
      destination: '/settings/profile',
      permissions: [],
      explicitIds: (event.data.recipientIds as number[]).map(id),
    };
  if (source === 'billing')
    return {
      ...base,
      kind:
        event.type === 'payment.failed'
          ? 'PAGO_RECHAZADO'
          : event.type === 'payment.reconciliation_required'
            ? 'PAGO_EN_VERIFICACION'
            : 'PAGO_CONFIRMADO',
      title:
        event.type === 'payment.failed'
          ? 'Pago rechazado'
          : event.type === 'payment.reconciliation_required'
            ? 'Estamos verificando tu pago'
            : 'Pago confirmado',
      message:
        event.type === 'payment.failed'
          ? 'El proveedor rechazó el cargo de la orden ' +
            event.data.order_reference +
            '. Puedes volver a intentarlo con otra tarjeta.'
          : event.type === 'payment.reconciliation_required'
            ? 'El resultado de la orden ' +
              event.data.order_reference +
              ' aún no está confirmado. No repitas el cobro; consulta su estado o solicita conciliación.'
            : 'Se confirmó el pago de ' +
              new Intl.NumberFormat('es-PE', {
                style: 'currency',
                currency: 'PEN',
              }).format(Number(event.data.amount_cents) / 100) +
              ' de la orden ' +
              event.data.order_reference +
              '. La activación se confirmará en un aviso separado.',
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
    case 'sampling.created':
      return {
        ...common,
        kind: 'MUESTREO_REGISTRADO',
        title: 'Muestreo pendiente de inspección',
        destination: '/samplings/' + String(event.data.samplingId),
        permissions: ['inspecciones.crear'],
      };
    case 'lot.retained':
      return {
        ...common,
        kind: 'LOTE_RETENIDO',
        title: 'Lote retenido: no disponible para producción',
        priority: 'high',
        permissions: ['produccion.ver'],
      };
    case 'lot.released':
      return {
        ...common,
        kind: 'LOTE_LIBERADO',
        title: 'Lote liberado nuevamente para producción',
        permissions: ['produccion.ver'],
      };
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
        permissions: ['incidencias.cerrar'],
        explicitIds: ((event.data.recipientIds ?? []) as number[]).map(id),
      };
    case 'incident.updated':
      return {
        ...common,
        kind: 'INCIDENCIA_SEGUIMIENTO',
        title: 'Seguimiento de incidencia: ' + String(event.data.status),
        priority: 'high',
        permissions: ['incidencias.cerrar'],
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
