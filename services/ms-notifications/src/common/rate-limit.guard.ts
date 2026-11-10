import {
  CanActivate,
  ExecutionContext,
  HttpException,
  HttpStatus,
  Injectable,
} from '@nestjs/common';
import type { Request } from 'express';
import { Database } from '../infrastructure/database/database.service.js';

@Injectable()
export class RateLimitGuard implements CanActivate {
  constructor(private readonly database: Database) {}
  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context
      .switchToHttp()
      .getRequest<
        Request & { actor?: { companyId: string; userId: string } }
      >();
    const key = request.actor
      ? `user:${request.actor.companyId}:${request.actor.userId}`
      : `ip:${request.ip ?? request.socket.remoteAddress ?? 'unknown'}`;
    const limit = request.actor ? 120 : 300;
    const rows = await this.database.query<{ hits: number }>(
      `
      INSERT INTO notification_rate_limits (key,window_start,hits) VALUES ($1,date_trunc('minute',now()),1)
      ON CONFLICT (key) DO UPDATE SET
        hits=CASE WHEN notification_rate_limits.window_start=date_trunc('minute',now()) THEN notification_rate_limits.hits+1 ELSE 1 END,
        window_start=date_trunc('minute',now()) RETURNING hits`,
      [key],
    );
    if (rows[0].hits > limit)
      throw new HttpException(
        'Too many requests.',
        HttpStatus.TOO_MANY_REQUESTS,
      );
    return true;
  }
}
