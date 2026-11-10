import { BadRequestException } from '@nestjs/common';

export function record(
  value: unknown,
  keys?: readonly string[],
): Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new BadRequestException('JSON object required.');
  const data = value as Record<string, unknown>;
  if (keys && Object.keys(data).some((key) => !keys.includes(key)))
    throw new BadRequestException('Unexpected field.');
  return data;
}
export function text(value: unknown, max = 150): string {
  if (
    typeof value !== 'string' ||
    value.length === 0 ||
    value.length > max ||
    Array.from(value).some((char) => char.charCodeAt(0) < 32)
  )
    throw new BadRequestException('Invalid string.');
  return value;
}
export function id(value: unknown): string {
  const normalized =
    typeof value === 'number' && Number.isSafeInteger(value)
      ? String(value)
      : value;
  if (
    typeof normalized !== 'string' ||
    !/^[1-9][0-9]{0,18}$/.test(normalized) ||
    BigInt(normalized) > 9223372036854775807n
  )
    throw new BadRequestException('Invalid numeric ID.');
  return normalized;
}
export function numericId(value: unknown): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1)
    throw new BadRequestException('A safe positive integer is required.');
  return value;
}
export function uuid(value: unknown): string {
  const normalized = text(value, 36).toLowerCase();
  if (
    !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
      normalized,
    )
  )
    throw new BadRequestException('Invalid UUID.');
  return normalized;
}
export function boolean(value: unknown): boolean {
  if (typeof value !== 'boolean')
    throw new BadRequestException('Boolean required.');
  return value;
}
export function strings(value: unknown, max = 20): string[] {
  if (!Array.isArray(value) || value.length > max)
    throw new BadRequestException('Invalid array.');
  return value.map((item) => text(item, 150));
}
