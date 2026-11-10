import 'reflect-metadata';
import { DataSource } from 'typeorm';
import { NotificationsInitial1794330000000 } from '../migrations/001_notifications.mjs';

if (!process.env.DATABASE_URL) throw new Error('DATABASE_URL is required.');
const source = new DataSource({
  type: 'postgres',
  url: process.env.DATABASE_URL,
  entities: [],
  synchronize: false,
  migrations: [NotificationsInitial1794330000000],
  migrationsTableName: 'notifications_schema_migrations',
  logging: false,
});
let lock;
try {
  await source.initialize();
  lock = source.createQueryRunner();
  await lock.connect();
  await lock.query(
    "SELECT pg_advisory_lock(hashtext('notifications:migrate'))",
  );
  const migrations = await source.runMigrations({ transaction: 'all' });
  process.stdout.write(`Applied ${migrations.length} migration(s).\n`);
} finally {
  try {
    if (lock && !lock.isReleased) {
      try {
        await lock.query(
          "SELECT pg_advisory_unlock(hashtext('notifications:migrate'))",
        );
      } finally {
        await lock.release();
      }
    }
  } finally {
    if (source.isInitialized) await source.destroy();
  }
}
