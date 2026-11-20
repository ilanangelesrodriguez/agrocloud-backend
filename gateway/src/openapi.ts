import { readFile } from 'node:fs/promises';

const services = [
  'identity',
  'agricultural',
  'notifications',
  'billing',
  'ai',
] as const;

const httpMethods = new Set([
  'get',
  'put',
  'post',
  'delete',
  'options',
  'head',
  'patch',
  'trace',
]);

interface ServiceContract {
  paths: Record<string, Record<string, unknown>>;
}

function gatewayPath(service: (typeof services)[number], path: string): string {
  const prefix = '/api/v1/' + service;
  if (service === 'notifications') {
    if (path === '/api/v1/notification-preferences')
      return prefix + '/preferences';
    if (path.startsWith('/api/v1/notifications')) return path;
    if (path === '/') return prefix;
  }
  return prefix + path;
}

export async function gatewayOpenApi() {
  const paths: ServiceContract['paths'] = {
    '/': {
      get: {
        tags: ['Gateway'],
        summary: 'Consultar el catálogo de servicios',
        responses: { '200': { description: 'Catálogo de APIs de AgroCloud' } },
      },
    },
    '/health': {
      get: {
        tags: ['Gateway'],
        summary: 'Comprobar el proceso del Gateway',
        responses: { '200': { description: 'El Gateway responde' } },
      },
    },
  };
  const tags = [{ name: 'Gateway' }];
  for (const service of services) {
    const source = new URL(
      '../../packages/contracts/openapi/' + service + '.yaml',
      import.meta.url,
    );
    const contract = JSON.parse(
      await readFile(source, 'utf8'),
    ) as ServiceContract;
    const label =
      service === 'ai' ? 'IA' : service[0].toUpperCase() + service.slice(1);
    const usedTags = new Set<string>();
    for (const [path, item] of Object.entries(contract.paths)) {
      if (
        path === '/' ||
        path.startsWith('/internal/') ||
        path.startsWith('/api/v1/internal/')
      )
        continue;
      const operations: ServiceContract['paths'][string] = {};
      for (const [method, value] of Object.entries(item)) {
        if (!httpMethods.has(method)) {
          operations[method] = value;
          continue;
        }
        const operation = value as Record<string, unknown>;
        const domains = operation.tags as string[] | undefined;
        const names = domains?.map((domain) => label + ' · ' + domain) ?? [
          label,
        ];
        names.forEach((name) => usedTags.add(name));
        operations[method] = {
          ...operation,
          tags: names,
          operationId: service + '_' + operation.operationId,
        };
      }
      paths[gatewayPath(service, path)] = operations;
    }
    for (const name of usedTags) tags.push({ name });
  }
  return {
    openapi: '3.0.3',
    info: {
      title: 'AgroCloud Gateway API',
      version: '1.0.0',
      description:
        'Catálogo interactivo de las APIs para clientes. Usa Authorize con el token de Identity para las operaciones protegidas. Billing e IA muestran sus rutas de estado. Las compras se inician en Identity y el análisis de evidencias en Agricultural.',
    },
    servers: [{ url: '/', description: 'Gateway actual' }],
    tags,
    paths,
    components: {
      securitySchemes: {
        bearerAuth: { type: 'http', scheme: 'bearer', bearerFormat: 'JWT' },
        apiKeyAuth: {
          type: 'apiKey',
          in: 'header',
          name: 'X-API-Key',
          description:
            'Private service credential; supplied by authorized server integrations, never exposed to the portal browser.',
        },
      },
    },
  };
}
