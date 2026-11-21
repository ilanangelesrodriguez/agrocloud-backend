import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';

@Schema({ collection: 'payment_attempts', timestamps: true, versionKey: false })
export class PaymentAttempt {
  @Prop({ required: true, index: true }) orderReference!: string;

  @Prop({
    required: true,
    enum: ['PROCESSING', 'REQUIRES_3DS', 'CANCELLED', 'SUCCEEDED', 'DECLINED', 'UNKNOWN'],
  })
  status!: string;

  @Prop() culqiChargeId?: string;

  @Prop() errorCode?: string;

  @Prop({ select: false }) tokenHash?: string;

  @Prop({ select: false }) deviceHash?: string;

  @Prop() authenticationExpiresAt?: Date;
}

export const PaymentAttemptSchema = SchemaFactory.createForClass(PaymentAttempt);

PaymentAttemptSchema.index({ orderReference: 1, createdAt: -1 });
