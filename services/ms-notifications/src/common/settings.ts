import { Injectable } from '@nestjs/common';

function required(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`${name} is required.`);
  return value;
}
function bounded(
  name: string,
  fallback: number,
  min: number,
  max: number,
): number {
  const value = Number(process.env[name] ?? fallback);
  if (!Number.isInteger(value) || value < min || value > max)
    throw new Error(`Invalid ${name}.`);
  return value;
}
function flag(name: string, fallback = false): boolean {
  const value = process.env[name] ?? String(fallback);
  if (!['true', 'false'].includes(value))
    throw new Error(`${name} must be true or false.`);
  return value === 'true';
}
@Injectable()
export class Settings {
  readonly production = process.env.NODE_ENV === 'production';
  readonly databaseUrl = required('DATABASE_URL');
  readonly identityUrl = required('IDENTITY_SERVICE_URL').replace(/\/$/, '');
  readonly identityKey = required('IDENTITY_SERVICE_API_KEY');
  readonly agriculturalSecret = process.env.AGRICULTURAL_EVENT_SECRET ?? '';
  readonly identityEventSecret = process.env.IDENTITY_EVENT_SECRET ?? '';
  readonly billingEventSecret = process.env.BILLING_EVENT_SECRET ?? '';
  readonly operatorKey = process.env.NOTIFICATIONS_OPERATOR_KEY ?? '';
  readonly frontendUrl = (
    process.env.FRONTEND_URL ?? 'http://localhost:3000'
  ).replace(/\/$/, '');
  readonly mailEnabled = flag('MAIL_ENABLED');
  readonly smtpUrl = process.env.SMTP_URL ?? '';
  readonly mailFrom = process.env.MAIL_FROM ?? '';
  readonly maxRecipients = bounded('MAX_EVENT_RECIPIENTS', 10000, 1, 100000);
  readonly workerInterval = bounded('WORKER_INTERVAL_MS', 1000, 250, 60000);
  readonly port = bounded('PORT', 3000, 1, 65535);
  constructor() {
    if (
      process.env.NODE_ENV &&
      !['development', 'test', 'production'].includes(process.env.NODE_ENV)
    )
      throw new Error('Invalid NODE_ENV.');
    const database = new URL(this.databaseUrl);
    const identity = new URL(this.identityUrl);
    const frontend = new URL(this.frontendUrl);
    if (!['postgres:', 'postgresql:'].includes(database.protocol))
      throw new Error('DATABASE_URL must use PostgreSQL.');
    for (const url of [identity, frontend]) {
      if (
        !['http:', 'https:'].includes(url.protocol) ||
        url.username ||
        url.password ||
        url.search ||
        url.hash ||
        url.pathname !== '/' ||
        (this.production && url.protocol !== 'https:')
      )
        throw new Error(
          'Configure HTTP origins without credentials, query or path; use HTTPS in production.',
        );
    }
    for (const [name, secret] of Object.entries({
      identityKey: this.identityKey,
      agriculturalSecret: this.agriculturalSecret,
      identityEventSecret: this.identityEventSecret,
      billingEventSecret: this.billingEventSecret,
      operatorKey: this.operatorKey,
    })) {
      if ((name === 'identityKey' || secret) && secret.length < 32)
        throw new Error(`${name} requires at least 32 characters.`);
    }
    if (this.mailEnabled) {
      if (
        !['smtp:', 'smtps:'].includes(new URL(this.smtpUrl).protocol) ||
        !/^[^\s<>@]+@[^\s<>@]+\.[^\s<>@]+$/.test(this.mailFrom)
      )
        throw new Error(
          'Configure SMTP_URL and MAIL_FROM when MAIL_ENABLED=true.',
        );
    }
  }
}
