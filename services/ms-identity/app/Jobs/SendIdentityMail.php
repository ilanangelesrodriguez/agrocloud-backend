<?php

namespace App\Jobs;

use App\Mail\IdentityTransactionalMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendIdentityMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    // Default keeps previously serialized jobs backward compatible.
    public string $kind = 'GENERAL';

    public function __construct(
        public int $attemptId,
        public string $address,
        public string $subject,
        public string $body,
        string $kind = 'GENERAL',
    ) {
        $this->kind = $kind;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array', 'failover'], true)) {
            throw new \RuntimeException('Configure a real mail transport; security tokens must not be logged.');
        }

        DB::table('security_mail_attempts')->where('id', $this->attemptId)->increment('attempts');

        Mail::to($this->address)->send(new IdentityTransactionalMail(
            $this->subject,
            $this->kind,
            $this->body,
        ));

        DB::table('security_mail_attempts')->where('id', $this->attemptId)->update([
            'status' => 'provider_accepted',
            'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('security_mail_attempts')->where('id', $this->attemptId)->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);
    }
}
