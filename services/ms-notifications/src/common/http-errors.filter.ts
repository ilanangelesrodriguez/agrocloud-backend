import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
} from '@nestjs/common';
import type { Response } from 'express';

@Catch()
export class HttpErrorsFilter implements ExceptionFilter {
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
      (error instanceof HttpException ? error.getStatus() : 503);
    response.status(status).setHeader('Cache-Control', 'no-store');
    response.setHeader('X-Content-Type-Options', 'nosniff');
    response.json({
      statusCode: status,
      message: parserStatus
        ? 'Invalid or oversized JSON request.'
        : error instanceof HttpException
          ? error.message
          : 'Service unavailable.',
    });
  }
}
