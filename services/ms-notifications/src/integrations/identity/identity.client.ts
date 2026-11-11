import {
  ForbiddenException,
  Injectable,
  ServiceUnavailableException,
  UnauthorizedException,
} from '@nestjs/common';
import { Settings } from '../../common/settings.js';
import { id, record, strings, text } from '../../common/validation.js';

export interface Actor {
  companyId: string;
  userId: string;
  permissions: string[];
}
export interface Recipient {
  companyId: string;
  userId: string;
  email: string;
}
@Injectable()
export class IdentityClient {
  constructor(private readonly settings: Settings) {}
  private async request(
    path: string,
    bearer?: string,
  ): Promise<Record<string, unknown>> {
    let response: Response;
    try {
      const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Service-Key': this.settings.identityKey,
        ...(bearer ? { Authorization: `Bearer ${bearer}` } : {}),
      };

      if (process.env.K_SERVICE) {
        const metadataUrl = new URL(
          'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity',
        );
        metadataUrl.searchParams.set('audience', this.settings.identityUrl);

        const metadata = await fetch(metadataUrl, {
          headers: { 'Metadata-Flavor': 'Google' },
          signal: AbortSignal.timeout(3000),
        });
        if (!metadata.ok)
          throw new Error('Cloud Run identity token unavailable');

        const idToken = (await metadata.text()).trim();
        if (idToken.split('.').length !== 3)
          throw new Error('Invalid Cloud Run identity token');
        headers['X-Serverless-Authorization'] = `Bearer ${idToken}`;
      }

      response = await fetch(
        this.settings.identityUrl + '/api/v1/internal' + path,
        {
          method: bearer ? 'POST' : 'GET',
          redirect: 'error',
          signal: AbortSignal.timeout(8000),
          headers,
        },
      );
    } catch {
      throw new ServiceUnavailableException('Identity unavailable.');
    }
    if (bearer && response.status === 401)
      throw new UnauthorizedException('Invalid or revoked token.');
    if (response.status === 403 || response.status === 404)
      throw new ForbiddenException('Identity denied access.');
    if (!response.ok)
      throw new ServiceUnavailableException('Identity unavailable.');
    try {
      const raw = await response.text();
      if (raw.length > 1000000) throw new Error('Oversized response');
      return record(JSON.parse(raw));
    } catch {
      throw new ServiceUnavailableException('Invalid Identity response.');
    }
  }
  async authenticate(token: string): Promise<Actor> {
    const response = await this.request('/auth/introspect', token);
    try {
      const data = record(response.data);
      if (data.estado !== true || data.pending_activation !== false)
        throw new Error('Inactive');
      return {
        companyId: id(data.id_empresa),
        userId: id(data.id_usuario),
        permissions: strings(data.permissions, 5000),
      };
    } catch {
      throw new ForbiddenException('An active company identity is required.');
    }
  }
  async recipients(
    companyId: string,
    permission: string,
  ): Promise<Recipient[]> {
    const result = new Map<string, Recipient>();
    const deadline = Date.now() + 60000;
    let lastPage = 1;
    for (let page = 1; page <= lastPage; page++) {
      if (Date.now() > deadline)
        throw new ServiceUnavailableException(
          'Recipient lookup exceeded its deadline.',
        );
      const response = await this.request(
        `/companies/${id(companyId)}/recipients?permission=${encodeURIComponent(permission)}&page=${page}`,
      );
      try {
        const last = Number(response.last_page);
        if (Number(response.current_page) !== page)
          throw new Error('Unexpected page');
        if (
          !Number.isSafeInteger(last) ||
          last < 1 ||
          last > 1000 ||
          !Array.isArray(response.data) ||
          response.data.length > 100
        )
          throw new Error('Invalid pagination');
        lastPage = last;
        for (const raw of response.data) {
          const user = record(raw);
          const tenant = id(user.id_empresa);
          if (tenant !== companyId) throw new Error('Wrong tenant');
          const email = text(user.correo, 254);
          if (!/^[^\s<>@,;]+@[^\s<>@,;]+\.[^\s<>@,;]+$/.test(email))
            throw new Error('Invalid address');
          result.set(id(user.id_usuario), {
            userId: id(user.id_usuario),
            companyId: tenant,
            email,
          });
          if (result.size > this.settings.maxRecipients)
            throw new Error('Recipient limit exceeded');
        }
      } catch {
        throw new ServiceUnavailableException(
          'Invalid or oversized recipient list; no recipients were truncated.',
        );
      }
    }
    return [...result.values()];
  }
}
