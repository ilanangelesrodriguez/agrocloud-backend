import { Body, Controller, Get, Put, Req, UseGuards } from '@nestjs/common';
import { AuthGuard, type ActorRequest } from '../../common/auth.guard.js';
import { RateLimitGuard } from '../../common/rate-limit.guard.js';
import { PreferencesService } from './preferences.service.js';

@Controller('api/v1/notification-preferences')
@UseGuards(AuthGuard, RateLimitGuard)
export class PreferencesController {
  constructor(private readonly preferences: PreferencesService) {}
  @Get() read(@Req() request: ActorRequest) {
    return this.preferences.read(request.actor);
  }
  @Put() replace(@Req() request: ActorRequest, @Body() body: unknown) {
    return this.preferences.replace(request.actor, body);
  }
}
