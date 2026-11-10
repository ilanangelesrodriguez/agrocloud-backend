import { readFile } from 'node:fs/promises';

export class NotificationsInitial1794330000000 {
  name = 'NotificationsInitial1794330000000';
  async up(queryRunner) {
    await queryRunner.query(
      await readFile(
        new URL('./001_notifications.sql', import.meta.url),
        'utf8',
      ),
    );
  }
  async down() {
    throw new Error(
      'Automatic rollback is disabled: preserve inbox and delivery history; prepare an explicit reviewed migration.',
    );
  }
}
