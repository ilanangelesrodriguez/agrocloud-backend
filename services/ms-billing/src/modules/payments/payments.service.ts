import {
  ConflictException,
  Injectable,
  Logger,
  UnprocessableEntityException,
} from '@nestjs/common';
import { InjectModel } from '@nestjs/mongoose';
import { createHash } from 'node:crypto';
import type { Model } from 'mongoose';
import { chargeMatchesOrder, ChargeSnapshot } from '../../common/utils/charge-verification.js';
import { CulqiHttpError, CulqiService } from '../../integrations/culqi/culqi.service.js';
import { BillingOrder } from '../orders/schemas/order.schema.js';
import { OrdersService } from '../orders/orders.service.js';
import { OutboxService } from '../../infrastructure/outbox/outbox.service.js';
import { CompleteChargeDto, CreateChargeDto } from './dto/create-charge.dto.js';
import { PaymentAttempt } from './schemas/payment-attempt.schema.js';

@Injectable()
export class PaymentsService {
  private readonly logger = new Logger(PaymentsService.name);
  constructor(
    @InjectModel(BillingOrder.name)
    private readonly ordersModel: Model<BillingOrder>,
    @InjectModel(PaymentAttempt.name)
    private readonly attempts: Model<PaymentAttempt>,
    private readonly orders: OrdersService,
    private readonly culqi: CulqiService,
    private readonly outbox: OutboxService,
  ) {}

  async charge(
    dto: CreateChargeDto,
    completion?: CompleteChargeDto,
  ): Promise<Record<string, unknown>> {
    const order = await this.orders.find(dto.externalReference);
    const digest = (value: string) => createHash('sha256').update(value).digest('hex');
    const pendingAttempt = completion
      ? await this.attempts.findOne({
          _id: completion.attemptId,
          orderReference: order.externalReference,
          status: 'REQUIRES_3DS',
          tokenHash: digest(dto.tokenId),
          deviceHash: digest(dto.deviceFingerprintId),
          authenticationExpiresAt: { $gt: new Date() },
        })
      : null;
    if (completion && !pendingAttempt)
      throw new ConflictException(
        'La autenticación expiró o no coincide con el token y dispositivo del intento original.',
      );
    const claimed = await this.ordersModel.findOneAndUpdate(
      {
        externalReference: dto.externalReference,
        status: completion ? 'REQUIRES_3DS' : 'PENDING',
        ...(completion ? { activeAttemptId: completion.attemptId } : {}),
      },
      { $set: { status: 'PROCESSING' } },
      { new: true },
    );
    if (!claimed)
      throw new ConflictException(
        `No se puede cobrar una orden con estado ${order.status}; consulta el pago o reconcilia antes de reintentar`,
      );

    const attempt = await (
      completion
        ? this.attempts
            .findOneAndUpdate(
              { _id: pendingAttempt!._id, status: 'REQUIRES_3DS' },
              { $set: { status: 'PROCESSING' } },
              { new: true },
            )
            .exec()
        : this.attempts.create({
            orderReference: order.externalReference,
            status: 'PROCESSING',
            tokenHash: digest(dto.tokenId),
            deviceHash: digest(dto.deviceFingerprintId),
          })
    ).catch(async (error: unknown) => {
      await this.ordersModel.updateOne(
        {
          externalReference: order.externalReference,
          status: 'PROCESSING',
          culqiChargeId: { $exists: false },
        },
        { $set: { status: completion ? 'REQUIRES_3DS' : 'PENDING' } },
      );
      throw error;
    });
    if (!attempt) {
      await this.ordersModel.updateOne(
        { externalReference: order.externalReference, status: 'PROCESSING' },
        { $set: { status: 'REQUIRES_3DS' } },
      );
      throw new ConflictException('Este intento de autenticación ya fue procesado.');
    }
    let chargeCreated = false;
    try {
      if (!completion) {
        const bound = await this.ordersModel.updateOne(
          { externalReference: order.externalReference, status: 'PROCESSING' },
          { $set: { activeAttemptId: String(attempt._id) } },
        );
        if (!bound.modifiedCount) throw new Error('No se pudo vincular el intento a la orden.');
      }
      const result = await this.culqi.charge({
        tokenId: dto.tokenId,
        amountCents: order.amountCents,
        email: order.customerEmail,
        description: order.description,
        reference: order.externalReference,
        deviceFingerprintId: dto.deviceFingerprintId,
        firstName: dto.firstName,
        lastName: dto.lastName,
        authentication3DS: completion?.authentication3DS,
      });
      if ('requires3ds' in result) {
        if (completion) throw new CulqiHttpError(422, 'Culqi no aceptó la autenticación 3DS.');
        const expiresAt = new Date(Date.now() + 10 * 60 * 1000);
        await this.attempts.updateOne(
          { _id: attempt._id },
          {
            $set: {
              status: 'REQUIRES_3DS',
              authenticationExpiresAt: expiresAt,
            },
          },
        );
        const updated = await this.ordersModel.updateOne(
          { externalReference: order.externalReference, status: 'PROCESSING' },
          { $set: { status: 'REQUIRES_3DS' } },
        );
        if (!updated.modifiedCount) throw new Error('La orden cambió durante la autenticación.');
        return {
          status: 'REQUIRES_3DS',
          orderReference: order.externalReference,
          attemptId: String(attempt._id),
          authenticationExpiresAt: expiresAt.toISOString(),
        };
      }
      chargeCreated = true;
      if (!result.id) throw new Error('Culqi no devolvió identificador de cargo');
      await this.ordersModel.updateOne(
        { externalReference: order.externalReference },
        { $set: { culqiChargeId: result.id } },
      );
      await this.attempts.updateOne({ _id: attempt._id }, { $set: { culqiChargeId: result.id } });

      const verified = await this.culqi.getCharge(result.id);
      if (!chargeMatchesOrder(verified, order))
        throw new Error('El cargo requiere revisión o autenticación adicional');
      await this.finalizeVerifiedCharge(order.externalReference, verified);
      await this.attempts.updateOne({ _id: attempt._id }, { $set: { status: 'SUCCEEDED' } });
      return {
        status: 'PAID',
        orderReference: order.externalReference,
        chargeId: verified.id,
      };
    } catch (error: unknown) {
      const declined = !chargeCreated && error instanceof CulqiHttpError && error.definitive;

      const current = await this.ordersModel.findOne({
        externalReference: order.externalReference,
      });
      if (current?.status === 'PAID') {
        await this.attempts.updateOne({ _id: attempt._id }, { $set: { status: 'SUCCEEDED' } });
        return {
          status: 'PAID',
          orderReference: order.externalReference,
          chargeId: current.culqiChargeId,
          notification: 'PENDING_RETRY',
        };
      }
      await this.ordersModel.updateOne(
        { externalReference: order.externalReference, status: 'PROCESSING' },
        { $set: { status: declined ? 'PENDING' : 'UNKNOWN' } },
      );
      await this.attempts.updateOne(
        { _id: attempt._id },
        {
          $set: {
            status: declined ? 'DECLINED' : 'UNKNOWN',
            errorCode: declined ? `HTTP_${error.status}` : 'RECONCILE_REQUIRED',
          },
        },
      );
      try {
        await this.outbox.enqueuePaymentIssue(
          order,
          String(attempt._id),
          declined ? 'DECLINED' : 'UNKNOWN',
        );
      } catch {
        this.logger.error({
          message: 'Payment notification enqueue failed; status lookup will retry',
          orderReference: order.externalReference,
        });
      }
      if (declined)
        throw new UnprocessableEntityException(
          'Culqi rechazó el cargo. Puedes utilizar otro token para reintentar.',
        );
      throw new ConflictException(
        'Resultado indeterminado: no repitas el cobro; primero reconcilia el estado con Culqi.',
      );
    }
  }

  async completeAuthentication(dto: CompleteChargeDto): Promise<Record<string, unknown>> {
    if (
      dto.authentication3DS.protocolVersion.startsWith('2.') &&
      !dto.authentication3DS.directoryServerTransactionId
    )
      throw new UnprocessableEntityException('Falta la transacción de autenticación 3DS.');
    return this.charge(dto, dto);
  }

  async cancelAuthentication(reference: string): Promise<Record<string, unknown>> {
    const order = await this.orders.find(reference);
    const updated = await this.ordersModel.findOneAndUpdate(
      {
        externalReference: reference,
        status: 'REQUIRES_3DS',
        activeAttemptId: order.activeAttemptId,
      },
      { $set: { status: 'PENDING' } },
      { new: true },
    );
    if (!updated)
      throw new ConflictException(
        'Solo se puede cancelar una autenticación pendiente; consulta el estado del pago.',
      );
    if (order.activeAttemptId)
      await this.attempts.updateOne(
        { _id: order.activeAttemptId, status: 'REQUIRES_3DS' },
        { $set: { status: 'CANCELLED', errorCode: 'AUTHENTICATION_CANCELLED' } },
      );
    return { status: 'PENDING', orderReference: reference };
  }

  async finalizeVerifiedCharge(
    reference: string,
    verified: ChargeSnapshot,
  ): Promise<{ status: string }> {
    const order = await this.orders.find(reference);
    if (!chargeMatchesOrder(verified, order))
      throw new ConflictException(
        'El cargo no coincide con el importe, moneda o referencia de la orden',
      );
    const updated = await this.ordersModel.findOneAndUpdate(
      { externalReference: reference, status: { $nin: ['PAID', 'REFUNDED'] } },
      {
        $set: {
          status: 'PAID',
          paidAt: new Date(),
          culqiChargeId: verified.id,
        },
      },
      { new: true },
    );
    const paid = updated ?? (await this.orders.find(reference));
    if (paid.status !== 'PAID') throw new ConflictException('La orden no admite esta confirmación');

    await this.outbox.enqueuePayment(paid);
    return { status: 'PAID' };
  }

  async reconcile(reference: string): Promise<Record<string, unknown>> {
    const order = await this.orders.find(reference);
    if (order.status === 'PAID') {
      await this.outbox.enqueuePayment(order);
      return { status: order.status, orderReference: reference };
    }
    if (order.status === 'REQUIRES_3DS')
      return { status: 'REQUIRES_3DS', orderReference: reference };
    if (!order.culqiChargeId)
      return {
        status: 'UNKNOWN',
        detail:
          'No existe ID de cargo; revisar operación en Culqi Panel antes de permitir otro intento',
      };
    const verified = await this.culqi.getCharge(order.culqiChargeId);
    if (chargeMatchesOrder(verified, order))
      return await this.finalizeVerifiedCharge(reference, verified);
    return {
      status: 'UNKNOWN',
      detail: 'El proveedor no confirma una venta exitosa',
    };
  }

  async status(reference: string): Promise<Record<string, unknown>> {
    const order = await this.orders.find(reference);
    if (order.status === 'PAID') {
      try {
        await this.outbox.enqueuePayment(order);
      } catch {
        this.logger.error({
          message: 'Confirmed payment event recovery failed',
          orderReference: reference,
        });
      }
    }
    const latest = await this.attempts
      .findOne({ orderReference: reference })
      .sort({ createdAt: -1 });
    if (
      latest &&
      ((order.status === 'PENDING' && latest.status === 'DECLINED') ||
        (order.status === 'UNKNOWN' && latest.status === 'UNKNOWN'))
    ) {
      try {
        await this.outbox.enqueuePaymentIssue(
          order,
          String(latest._id),
          latest.status as 'DECLINED' | 'UNKNOWN',
        );
      } catch {
        this.logger.error({
          message: 'Payment notification recovery failed',
          orderReference: reference,
        });
      }
    }
    return {
      orderReference: reference,
      status: order.status,
      chargeId: order.culqiChargeId ?? null,
      lastAttempt: latest?.status ?? null,
    };
  }
}
