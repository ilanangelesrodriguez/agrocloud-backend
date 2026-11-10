import { Module } from '@nestjs/common';
import { AppController } from './app.controller.js';
import { ReadinessController } from './infrastructure/database/readiness.controller.js';
import { AppService } from './app.service.js';
import { DatabaseModule } from './infrastructure/database/database.module.js';
import { IdentityModule } from './integrations/identity/identity.module.js';
import { RateLimitGuard } from './common/rate-limit.guard.js';
import { NotificationsController } from './modules/notifications/notifications.controller.js';
import { NotificationsService } from './modules/notifications/notifications.service.js';
import { PreferencesController } from './modules/preferences/preferences.controller.js';
import { PreferencesService } from './modules/preferences/preferences.service.js';
import { EventsController } from './modules/events/events.controller.js';
import { EventsService } from './modules/events/events.service.js';
import {
  OperationsController,
  OperatorGuard,
} from './modules/events/operations.controller.js';

@Module({
  imports: [DatabaseModule, IdentityModule],
  controllers: [
    AppController,
    ReadinessController,
    NotificationsController,
    PreferencesController,
    EventsController,
    OperationsController,
  ],
  providers: [
    AppService,
    RateLimitGuard,
    NotificationsService,
    PreferencesService,
    EventsService,
    OperatorGuard,
  ],
})
export class AppModule {}
