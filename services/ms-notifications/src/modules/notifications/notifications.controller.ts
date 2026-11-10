import {
  Controller,
  Get,
  Param,
  Patch,
  Query,
  Req,
  UseGuards,
} from '@nestjs/common';
import { AuthGuard, type ActorRequest } from '../../common/auth.guard.js';
import { RateLimitGuard } from '../../common/rate-limit.guard.js';
import { NotificationsService } from './notifications.service.js';

@Controller('api/v1/notifications')
@UseGuards(AuthGuard, RateLimitGuard)
export class NotificationsController {
  constructor(private readonly notifications: NotificationsService) {}
  @Get() list(@Req() request: ActorRequest, @Query() query: unknown) {
    return this.notifications.list(request.actor, query);
  }
  @Get('unread-count') unread(@Req() request: ActorRequest) {
    return this.notifications.unread(request.actor);
  }
  @Patch('read-all') readAll(@Req() request: ActorRequest) {
    return this.notifications.markAllRead(request.actor);
  }
  @Patch(':id/read') read(
    @Req() request: ActorRequest,
    @Param('id') id: string,
  ) {
    return this.notifications.markRead(request.actor, id);
  }
}
