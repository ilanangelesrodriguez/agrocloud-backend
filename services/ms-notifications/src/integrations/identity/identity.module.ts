import { Global, Module } from '@nestjs/common';
import { AuthGuard } from '../../common/auth.guard.js';
import { IdentityClient } from './identity.client.js';

@Global()
@Module({
  providers: [IdentityClient, AuthGuard],
  exports: [IdentityClient, AuthGuard],
})
export class IdentityModule {}
