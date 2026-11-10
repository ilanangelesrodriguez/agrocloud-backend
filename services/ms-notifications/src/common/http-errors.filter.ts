import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
  Logger,
} from '@nestjs/common';
import type { Response } from 'express';

@Catch()
export class HttpErrorsFilter implements ExceptionFilter {
  private readonly logger = new Logger(HttpErrorsFilter.name);

  catch(error: unknown, host: ArgumentsHost): void {
    const response = host.switchToHttp().getResponse<Response>();
    if (response.headersSent) {
      response.destroy();
      return;
    }
    const parserType =
      typeof error === 'object' && error !== null && 'type' in error
        ? error.type
        : undefined;
    const parserStatus =
      parserType === 'entity.too.large'
        ? 413
        : parserType === 'entity.parse.failed'
          ? 400
          : undefined;
    const status =
      parserStatus ??
      (error instanceof HttpException ? error.getStatus() : 500);
    response.status(status).setHeader('Cache-Control', 'no-store');
    response.setHeader('X-Content-Type-Options', 'nosniff');
    const requestId = response.getHeader('X-Request-Id');
    if (status === 500)
      this.logger.error({ message: 'Unhandled HTTP error', requestId });
    response.json({
      statusCode: status,
      requestId,
      message: parserStatus
        ? 'Invalid or oversized JSON request.'
        : error instanceof HttpException
          ? error.message
          : 'Internal server error.',
    });
  }
}
