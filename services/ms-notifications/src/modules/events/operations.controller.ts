import {
  CanActivate,
  Controller,
  ExecutionContext,
  Get,
  HttpCode,
  Injectable,
  Param,
  Post,
  UnauthorizedException,
  UseGuards,
  ConflictException,
} from '@nestjs/common';
import { timingSafeEqual } from 'node:crypto';
import type { Request } from 'express';
import { Settings } from '../../common/settings.js';
import { RateLimitGuard } from '../../common/rate-limit.guard.js';
import { Database } from '../../infrastructure/database/database.service.js';
import { text, uuid } from '../../common/validation.js';

@Injectable()
export class OperatorGuard implements CanActivate {
  constructor(private readonly settings: Settings) {}
  canActivate(context: ExecutionContext): boolean {
    const header =
      context
        .switchToHttp()
        .getRequest<Request>()
        .get('X-Notifications-Operator-Key') ?? '';
    const expected = Buffer.from(this.settings.operatorKey);
    const actual = Buffer.from(header);
    if (
      expected.length < 32 ||
      actual.length !== expected.length ||
      !timingSafeEqual(expected, actual)
    )
      throw new UnauthorizedException('Invalid operator credentials.');
    return true;
  }
}
@Controller('api/v1/internal/operations')
@UseGuards(OperatorGuard, RateLimitGuard)
export class OperationsController {
  constructor(private readonly database: Database) {}
  @Get('status')
  async status() {
    return {
      events: await this.database.query(
        'SELECT status,count(*)::text AS count FROM processed_events GROUP BY status',
      ),
      deliveries: await this.database.query(
        'SELECT status,count(*)::text AS count FROM notification_deliveries GROUP BY status',
      ),
    };
  }
  @Post('events/:source/:eventId/retry')
  @HttpCode(202)
  async retryEvent(
    @Param('source') source: string,
    @Param('eventId') eventId: string,
  ) {
    if (!['agricultural', 'identity', 'billing'].includes(source))
      throw new ConflictException('Invalid source.');
    const rows = await this.database.query(
      `UPDATE processed_events SET status='PENDING',attempts=0,next_attempt_at=now(),last_error_code=NULL
      WHERE source=$1 AND event_id=$2 AND status='FAILED' AND (locked_until IS NULL OR locked_until<now()) RETURNING event_id`,
      [source, text(eventId, 150)],
    );
    if (!rows.length)
      throw new ConflictException(
        'Only failed, unlocked events can be replayed.',
      );
    return { queued: true };
  }
  @Post('deliveries/:id/retry')
  @HttpCode(202)
  async retryDelivery(@Param('id') id: string) {
    const rows = await this.database.query(
      `UPDATE notification_deliveries SET status='PENDING',attempts=0,next_attempt_at=now(),last_error_code=NULL
      WHERE id=$1 AND status='FAILED' AND (locked_until IS NULL OR locked_until<now()) RETURNING id`,
      [uuid(id)],
    );
    if (!rows.length)
      throw new ConflictException(
        'Only failed, unlocked deliveries can be retried.',
      );
    return { queued: true };
  }
}
