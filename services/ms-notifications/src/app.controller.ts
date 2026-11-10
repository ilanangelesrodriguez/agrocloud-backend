import { readFile } from 'node:fs/promises';
import type { Response } from 'express';
import { Controller, Get, NotFoundException, Res } from '@nestjs/common';
import { AppService } from './app.service.js';

@Controller()
export class AppController {
  constructor(private readonly appService: AppService) {}
  @Get('health') health() {
    return { status: 'ok', service: 'ms-notifications' };
  }
  @Get('docs')
  async docs(@Res() response: Response): Promise<void> {
    this.requireDevelopment();
    const template = await readFile(
      new URL('../docs.html', import.meta.url),
      'utf8',
    );
    const methods =
      process.env.SWAGGER_TRY_IT_OUT === 'true'
        ? ['get', 'post', 'put', 'patch', 'delete']
        : [];
    response
      .type('html')
      .send(
        template.replace('__SWAGGER_SUBMIT_METHODS__', JSON.stringify(methods)),
      );
  }

  @Get('openapi.json')
  async openapi() {
    this.requireDevelopment();
    const contract = JSON.parse(
      await readFile(new URL('../openapi.yaml', import.meta.url), 'utf8'),
    ) as {
      paths: Record<string, unknown>;
      tags: { name: string }[];
      components: { securitySchemes: Record<string, { type: string }> };
    };
    for (const path of Object.keys(contract.paths)) {
      if (path.startsWith('/api/v1/internal/')) delete contract.paths[path];
    }
    contract.tags = contract.tags.filter(
      ({ name }) =>
        name !== 'Eventos internos' && name !== 'Operaciones internas',
    );
    for (const [name, scheme] of Object.entries(
      contract.components.securitySchemes,
    )) {
      if (scheme.type === 'apiKey')
        delete contract.components.securitySchemes[name];
    }
    return contract;
  }

  private requireDevelopment(): void {
    if (process.env.NODE_ENV !== 'development') throw new NotFoundException();
  }

  @Get() index() {
    return this.appService.index();
  }
}
