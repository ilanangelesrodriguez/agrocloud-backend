export class PortalDestinations1795190400000 {
  name = 'PortalDestinations1795190400000';
  async up(queryRunner) {
    await queryRunner.query(`
      UPDATE notifications SET destination = CASE
        WHEN destination = '/notificaciones' THEN '/notifications'
        WHEN destination = '/suscripcion' THEN '/settings/subscription'
        ELSE regexp_replace(destination, '^/lotes/', '/lots/')
      END
      WHERE destination IN ('/notificaciones', '/suscripcion')
        OR destination ~ '^/lotes/[1-9][0-9]*$'
    `);
  }
  async down() {
    throw new Error(
      'Preserve current portal destinations; use an explicit corrective migration.',
    );
  }
}
