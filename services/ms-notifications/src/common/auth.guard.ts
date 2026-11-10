import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import type { Request } from 'express';
import {
  IdentityClient,
  type Actor,
} from '../integrations/identity/identity.client.js';

export interface ActorRequest extends Request {
  actor: Actor;
}
@Injectable()
export class AuthGuard implements CanActivate {
  constructor(private readonly identity: IdentityClient) {}
  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context.switchToHttp().getRequest<ActorRequest>();
    const header = request.headers.authorization;
    if (typeof header !== 'string' || !/^Bearer [^\s,]{1,8192}$/.test(header))
      throw new UnauthorizedException('Bearer token required.');
    request.actor = await this.identity.authenticate(header.slice(7));
    return true;
  }
}
