import { fileURLToPath } from 'node:url';
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
  docs(@Res() response: Response): void {
    this.sendDocumentation(response, '../docs.html', 'text/html');
  }

  @Get('openapi.json')
  openapi(@Res() response: Response): void {
    this.sendDocumentation(response, '../openapi.yaml', 'application/json');
  }

  private sendDocumentation(
    response: Response,
    path: string,
    mediaType: string,
  ): void {
    if (process.env.NODE_ENV !== 'development') throw new NotFoundException();
    response
      .type(mediaType)
      .sendFile(fileURLToPath(new URL(path, import.meta.url)));
  }

  @Get() index() {
    return this.appService.index();
  }
}
