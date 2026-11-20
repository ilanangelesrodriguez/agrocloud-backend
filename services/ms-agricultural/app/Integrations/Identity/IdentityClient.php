<?php

namespace App\Integrations\Identity;

use App\Shared\Auth\Actor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class IdentityClient
{
    public function call(string $method, string $path, array $data = [], ?string $bearer = null): array
    {
        $url = rtrim((string) config('agricultural.identity_url'), '/');
        $key = (string) config('agricultural.identity_key');
        abort_unless(strlen($key) >= 32 && filter_var($url, FILTER_VALIDATE_URL)
            && (str_starts_with($url, 'https://') || app()->environment('local', 'testing')), 503, 'Configure Identity service connection.');
        try {
            $request = Http::withHeaders(['X-Service-Key' => $key])->acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(8);
            if (app()->environment('production')) {
                $tokenResponse = Http::withHeaders([
                    'Metadata-Flavor' => 'Google',
                ])->connectTimeout(2)->timeout(5)->get(
                    'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity',
                    ['audience' => $url]
                );

                abort_unless(
                    $tokenResponse->successful()
                    && substr_count(trim($tokenResponse->body()), '.') === 2,
                    503,
                    'Cloud Run identity token unavailable.'
                );

                $request = $request->withHeaders([
                    'X-Serverless-Authorization' => 'Bearer '.trim($tokenResponse->body()),
                ]);
            }

            if ($bearer !== null) {
                $request = $request->withToken($bearer);
            }
            $response = $method === 'GET' ? $request->get($url.'/api/v1/internal'.$path, $data) : $request->post($url.'/api/v1/internal'.$path, $data);
        } catch (ConnectionException) {
            abort(503, 'Identity unavailable.');
        }
        if ($bearer !== null && $response->status() === 401) {
            throw new AuthenticationException('Invalid or revoked access token.');
        }
        abort_if($response->status() === 403, 403, 'Identity denied access or quota.');
        abort_if($response->status() === 409, 409, 'Identity operation conflict.');
        abort_unless($response->successful() && is_array($response->json()), 503, 'Identity returned an unavailable or invalid response.');

        return $response->json();
    }

    public function authenticate(string $token): Actor
    {
        $payload = $this->call('POST', '/auth/introspect', bearer: $token)['data'] ?? [];
        $data = Validator::make($payload, [
            'id_usuario' => ['required', 'integer', 'min:1'], 'id_empresa' => ['required', 'integer', 'min:1'],
            'estado' => ['required', 'accepted'], 'rol.codigo' => ['required', 'string'],
            'permissions' => ['present', 'array'], 'permissions.*' => ['string'],
        ]);
        abort_if($data->fails(), 403, 'An active company identity is required.');

        return new Actor(['id' => (int) $payload['id_usuario'], 'id_usuario' => (int) $payload['id_usuario'],
            'id_empresa' => (int) $payload['id_empresa'], 'rol' => (object) $payload['rol'], 'permissions' => $payload['permissions']]);
    }

    public function consume(int $company, string $requestId, string $resource): void
    {
        $result = $this->call('POST', '/companies/'.$company.'/usage', ['request_id' => $requestId, 'recurso' => $resource, 'cantidad' => 1]);
        abort_unless(($result['consumed'] ?? false) === true, 503, 'Quota confirmation unavailable.');
    }

    public function recipient(int $company, int $user): void
    {
        $page = 1;
        do {
            $data = $this->call('GET', '/companies/'.$company.'/recipients', ['permission' => 'incidencias.ver', 'page' => $page]);
            foreach ($data['data'] ?? [] as $recipient) {
                if ((int) $recipient['id_usuario'] === $user) {
                    return;
                }
            }
            $page++;
        } while ($page <= min(1000, (int) ($data['last_page'] ?? 1)));
        abort(422, 'Responsible user is not active or authorized in this company.');
    }
}
