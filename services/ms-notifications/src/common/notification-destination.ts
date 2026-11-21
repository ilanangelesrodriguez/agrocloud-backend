/** Keep historical email destinations within the current portal routes. */
export function notificationDestination(destination: string): string {
  if (/^\/lotes\/[1-9]\d*$/.test(destination))
    return destination.replace('/lotes/', '/lots/');
  if (destination === '/notificaciones') return '/notifications';
  if (destination === '/suscripcion') return '/settings/subscription';
  if (
    /^\/(?:lots|inspections|samplings)\/[1-9]\d*(?:\/(?:reception|sampling|evidences))?$/.test(
      destination,
    ) ||
    [
      '/notifications',
      '/settings/subscription',
      '/settings/profile',
      '/settings/users',
    ].includes(destination)
  )
    return destination;
  return '/notifications';
}
