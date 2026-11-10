import { Module } from '@nestjs/common';
import { MailModule, SmtpTransport } from '@nestjs/mail';
import { DatabaseModule } from './infrastructure/database/database.module.js';
import { IdentityModule } from './integrations/identity/identity.module.js';
import { Settings } from './common/settings.js';
import { EventsWorker } from './modules/events/events.worker.js';
import { DeliveriesWorker } from './modules/deliveries/deliveries.worker.js';

const settings = new Settings();
@Module({
  imports: [
    DatabaseModule,
    IdentityModule,
    ...(settings.mailEnabled
      ? [
          MailModule.forRoot({
            from: settings.mailFrom,
            retry: false,
            transport: new SmtpTransport({
              url: settings.smtpUrl,
              startTls: settings.production ? 'required' : undefined,
              timeouts: {
                connect: '5s',
                greeting: '5s',
                command: '10s',
                data: '20s',
              },
            }),
          }),
        ]
      : []),
  ],
  providers: [EventsWorker, DeliveriesWorker],
})
export class WorkerModule {}
