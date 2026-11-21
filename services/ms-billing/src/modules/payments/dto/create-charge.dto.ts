import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  IsDefined,
  IsOptional,
  IsString,
  IsUUID,
  Matches,
  MaxLength,
  ValidateNested,
} from 'class-validator';

export class CreateChargeDto {
  @ApiProperty({ example: 'AGRO-ORDER-000125' })
  @IsString()
  @Matches(/^[A-Za-z0-9_-]{8,36}$/)
  externalReference!: string;

  @ApiProperty({
    example: 'tkn_test_xxxxx',
    description: 'Token generado por Culqi Custom Checkout; NO datos de tarjeta',
  })
  @IsString()
  @Matches(/^tkn_(test|live)_[A-Za-z0-9]+$/)
  @MaxLength(128)
  tokenId!: string;

  @ApiProperty({ description: 'Device generado por Culqi3DS antes del primer cargo' })
  @IsUUID('4')
  deviceFingerprintId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(100)
  firstName?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(100)
  lastName?: string;
}

export class Authentication3DSDto {
  @ApiProperty()
  @IsString()
  @Matches(/^\d{2}$/)
  eci!: string;

  @ApiProperty()
  @IsString()
  @Matches(/^[A-Za-z0-9+/=_-]{1,256}$/)
  xid!: string;

  @ApiProperty()
  @IsString()
  @Matches(/^[A-Za-z0-9+/=_-]{1,256}$/)
  cavv!: string;

  @ApiProperty()
  @IsString()
  @Matches(/^[12]\.\d{1,2}\.\d{1,2}$/)
  protocolVersion!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  directoryServerTransactionId?: string;
}

export class CompleteChargeDto extends CreateChargeDto {
  @ApiProperty()
  @Matches(/^[a-f0-9]{24}$/)
  attemptId!: string;

  @ApiProperty({ type: Authentication3DSDto })
  @IsDefined()
  @ValidateNested()
  @Type(() => Authentication3DSDto)
  authentication3DS!: Authentication3DSDto;
}
