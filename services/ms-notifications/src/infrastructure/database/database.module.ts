import { Global, Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { Settings } from '../../common/settings.js';
import { Database } from './database.service.js';

@Global()
@Module({
  imports: [
    TypeOrmModule.forRootAsync({
      useFactory: () => {
        const settings = new Settings();
        return {
          type: 'postgres' as const,
          url: settings.databaseUrl,
          entities: [],
          synchronize: false,
          migrationsRun: false,
          logging: false,
          retryAttempts: 1,
          extra: {
            max: 10,
            connectionTimeoutMillis: 5000,
            statement_timeout: 15000,
            query_timeout: 20000,
          },
        };
      },
    }),
  ],
  providers: [Settings, Database],
  exports: [Settings, Database],
})
export class DatabaseModule {}
