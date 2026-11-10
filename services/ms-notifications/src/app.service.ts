import { Injectable } from '@nestjs/common';

@Injectable()
export class AppService {
  index() {
    const docsEnabled = process.env.NODE_ENV === 'development';
    return {
      service: 'ms-notifications',
      description:
        'Bandeja de notificaciones, preferencias y entregas de AgroCloud.',
      apiVersion: 'v1',
      links: {
        documentation: docsEnabled ? '/docs' : null,
        openapi: docsEnabled ? '/openapi.json' : null,
        health: '/health',
        ready: '/ready',
        inbox: '/api/v1/notifications',
        unreadCount: '/api/v1/notifications/unread-count',
        preferences: '/api/v1/notification-preferences',
      },
    };
  }
}
