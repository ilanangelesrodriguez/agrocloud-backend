import { Controller, Get, Header } from '@nestjs/common';
import { gatewayOpenApi } from './openapi.js';
import { swaggerPage } from './swagger.js';
import { AppService } from './app.service.js';

@Controller()
export class AppController {
  constructor(private readonly appService: AppService) {}

  @Get('docs')
  @Header('Content-Type', 'text/html; charset=utf-8')
  docs() {
    return swaggerPage;
  }

  @Get('openapi.json')
  openapi() {
    return gatewayOpenApi();
  }

  @Get('health')
  health() {
    return { status: 'ok', service: 'gateway' };
  }

  @Get()
  index() {
    return this.appService.index();
  }
}
