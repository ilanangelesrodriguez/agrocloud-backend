import {
  Body,
  Controller,
  Headers,
  HttpCode,
  Post,
  Req,
  UseGuards,
} from '@nestjs/common';
import type { RawBodyRequest } from '@nestjs/common';
import type { Request } from 'express';
import { RateLimitGuard } from '../../common/rate-limit.guard.js';
import { EventsService } from './events.service.js';

@Controller('api/v1/internal/events')
@UseGuards(RateLimitGuard)
export class EventsController {
  constructor(private readonly events: EventsService) {}
  @Post('agricultural')
  @HttpCode(202)
  agricultural(
    @Body() body: unknown,
    @Req() req: RawBodyRequest<Request>,
    @Headers('x-agrocloud-signature') signature?: string,
    @Headers('x-event-id') id?: string,
  ) {
    return this.events.accept('agricultural', body, req.rawBody, signature, id);
  }
  @Post('identity')
  @HttpCode(202)
  identity(
    @Body() body: unknown,
    @Req() req: RawBodyRequest<Request>,
    @Headers('x-agrocloud-signature') signature?: string,
    @Headers('x-event-id') id?: string,
  ) {
    return this.events.accept('identity', body, req.rawBody, signature, id);
  }
  @Post('billing')
  @HttpCode(202)
  billing(
    @Body() body: unknown,
    @Req() req: RawBodyRequest<Request>,
    @Headers('x-agrocloud-signature') signature?: string,
    @Headers('x-event-id') id?: string,
  ) {
    return this.events.accept('billing', body, req.rawBody, signature, id);
  }
}
