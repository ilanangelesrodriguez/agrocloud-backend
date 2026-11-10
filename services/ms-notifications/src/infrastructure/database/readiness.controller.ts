import { Controller, Get, ServiceUnavailableException } from '@nestjs/common';
import { Database } from './database.service.js';

@Controller()
export class ReadinessController {
  constructor(private readonly database: Database) {}
  @Get('ready')
  async ready() {
    try {
      for (const table of [
        'notifications',
        'processed_events',
        'notification_preferences',
        'notification_deliveries',
        'notification_rate_limits',
      ]) {
        await this.database.query(`SELECT 1 FROM ${table} LIMIT 0`);
      }
      return { ready: true, database: true, upstreamsChecked: false };
    } catch {
      throw new ServiceUnavailableException('Database or schema unavailable.');
    }
  }
}
