<?php

namespace App\Services\Subscriptions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class BillingHttpClient
{
    public function request(string $url, string $key): PendingRequest
    {
        $headers = ['X-Service-Key' => $key];
        $audience = (string) config('identity.billing.cloud_run_audience');
        if ($audience !== '') {
            abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https', 503, 'Billing IAM requires HTTPS.');
            $metadata = Http::withHeaders(['Metadata-Flavor' => 'Google'])->withoutRedirecting()
                ->connectTimeout(2)->timeout(3)
                ->get('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity', [
                    'audience' => $audience,
                ]);
            $token = trim($metadata->body());
            abort_unless($metadata->ok() && preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/D', $token),
                503, 'Cloud Run authentication for Billing is unavailable.');
            $headers['X-Serverless-Authorization'] = 'Bearer '.$token;
        }

        return Http::withHeaders($headers)->acceptJson()->withoutRedirecting()->connectTimeout(3);
    }
}
