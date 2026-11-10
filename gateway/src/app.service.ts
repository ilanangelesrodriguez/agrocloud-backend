import { Injectable } from '@nestjs/common';

@Injectable()
export class AppService {
  index() {
    return {
      service: 'agrocloud-gateway',
      description: 'Punto de entrada a las APIs de AgroCloud.',
      apiVersion: 'v1',
      links: { health: '/health' },
      services: [
        {
          name: 'Identity',
          description: 'Autenticación, empresas, usuarios y suscripciones.',
          prefix: '/api/v1/identity',
          routes: [
            'POST /api/v1/identity/auth/login',
            'GET /api/v1/identity/auth/me',
          ],
        },
        {
          name: 'Agricultural',
          description: 'Productores, lotes, muestreos, inspecciones y calidad.',
          prefix: '/api/v1/agricultural',
          routes: [
            'GET /api/v1/agricultural/lots',
            'GET /api/v1/agricultural/inspections',
          ],
        },
        {
          name: 'Notifications',
          description: 'Bandeja y preferencias de notificaciones.',
          prefix: '/api/v1/notifications',
          routes: [
            'GET /api/v1/notifications',
            'GET /api/v1/notifications/preferences',
          ],
        },
        {
          name: 'Billing',
          description:
            'Órdenes, pagos y reembolsos. Las compras del navegador pasan por Identity.',
          prefix: '/api/v1/billing',
          routes: [],
        },
        {
          name: 'AI',
          description:
            'Análisis de imágenes. Las solicitudes del navegador pasan por Agricultural.',
          prefix: '/api/v1/ai',
          routes: [],
        },
      ].map(({ prefix, ...service }) => ({
        ...service,
        prefix,
        health: prefix + '/health',
        ready: prefix + '/ready',
      })),
    };
  }
}
