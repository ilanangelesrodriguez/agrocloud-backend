import { Injectable, ServiceUnavailableException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import type { ChargeSnapshot } from '../../common/utils/charge-verification.js';
import type { Authentication3DSDto } from '../../modules/payments/dto/create-charge.dto.js';

export type CulqiChargeResult = ChargeSnapshot | { requires3ds: true };

export class CulqiHttpError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message);
  }

  get definitive(): boolean {
    return [400, 402, 422].includes(this.status);
  }
}

@Injectable()
export class CulqiService {
  private readonly base = 'https://api.culqi.com/v2';

  constructor(private readonly config: ConfigService) {}

  private get key(): string {
    return this.config.getOrThrow<string>('CULQI_SECRET_KEY');
  }

  private async request(
    path: string,
    method = 'GET',
    body?: Record<string, unknown>,
  ): Promise<Record<string, unknown>> {
    let response: Response;
    try {
      response = await fetch(`${this.base}${path}`, {
        method,
        headers: { Authorization: `Bearer ${this.key}`, 'Content-Type': 'application/json' },
        ...(body ? { body: JSON.stringify(body) } : {}),
        signal: AbortSignal.timeout(15000),
      });
    } catch {
      throw new ServiceUnavailableException(
        'No se pudo determinar el resultado del proveedor; requiere conciliación',
      );
    }
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok)
      throw new CulqiHttpError(
        response.status,
        `Culqi rechazó o no completó la solicitud (HTTP ${response.status})`,
      );
    if (!payload || typeof payload !== 'object' || Array.isArray(payload))
      throw new ServiceUnavailableException('Respuesta de Culqi no verificable');
    return payload as Record<string, unknown>;
  }

  async charge(data: {
    tokenId: string;
    amountCents: number;
    email: string;
    reference: string;
    description: string;
    deviceFingerprintId: string;
    firstName?: string;
    lastName?: string;
    authentication3DS?: Authentication3DSDto;
  }): Promise<CulqiChargeResult> {
    const result = await this.request('/charges', 'POST', {
      amount: data.amountCents,
      currency_code: 'PEN',
      email: data.email,
      source_id: data.tokenId,
      description: data.description,
      metadata: { external_reference: data.reference },
      antifraud_details: {
        device_finger_print_id: data.deviceFingerprintId,
        ...(data.firstName ? { first_name: data.firstName } : {}),
        ...(data.lastName ? { last_name: data.lastName } : {}),
      },
      ...(data.authentication3DS ? { authentication_3DS: data.authentication3DS } : {}),
    });
    const outcome = result.outcome as Record<string, unknown> | undefined;
    if ((result.action_code === 'REVIEW' || outcome?.action_code === 'REVIEW') && !result.id)
      return { requires3ds: true };
    return result as unknown as ChargeSnapshot;
  }

  async getCharge(id: string): Promise<ChargeSnapshot> {
    if (!/^chr_(test|live)_[A-Za-z0-9]+$/.test(id)) throw new Error('ID de cargo inválido');
    return (await this.request(`/charges/${encodeURIComponent(id)}`)) as unknown as ChargeSnapshot;
  }

  async refund(data: {
    chargeId: string;
    amountCents: number;
    reason: string;
  }): Promise<Record<string, unknown>> {
    return await this.request('/refunds', 'POST', {
      charge_id: data.chargeId,
      amount: data.amountCents,
      reason: data.reason,
    });
  }
}
