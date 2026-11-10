import { Injectable } from '@nestjs/common';
import { InjectDataSource } from '@nestjs/typeorm';
import { DataSource, type EntityManager } from 'typeorm';

@Injectable()
export class Database {
  constructor(@InjectDataSource() private readonly source: DataSource) {}
  query<T extends object>(sql: string, params: unknown[] = []): Promise<T[]> {
    return this.source.query<T[]>(sql, params);
  }
  transaction<T>(callback: (manager: EntityManager) => Promise<T>): Promise<T> {
    return this.source.transaction(callback);
  }
}
