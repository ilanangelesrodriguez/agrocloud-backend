<?php

namespace App\Integrations\Ai;

use App\Models\EvidenciaInspeccion;
use App\Modules\Evidences\EvidenceStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AiAnalysisClient
{
    public function __construct(private readonly EvidenceStorage $storage) {}

    public function analyze(EvidenciaInspeccion $photo, ?string $correlationId = null): array
    {
        $url = rtrim((string) config('agricultural.ai_url'), '/');
        $key = (string) config('agricultural.ai_key');
        abort_unless(strlen($key) >= 24 && filter_var($url, FILTER_VALIDATE_URL)
            && (str_starts_with($url, 'https://') || app()->environment('local', 'testing')), 503, 'Configure AI service.');
        $bytes = $this->storage->read($photo->file_path);
        if (! hash_equals($photo->image_sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('Evidence input hash mismatch.', 422);
        }
        $request = Http::withHeaders(['X-API-Key' => $key, 'X-Correlation-ID' => $correlationId ?? (string) $photo->id_evidencia])->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(105);
        if (config('agricultural.ai_iam_enabled')) {
            $token = Http::withHeaders(['Metadata-Flavor' => 'Google'])->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->get('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity',
                    ['audience' => config('agricultural.ai_audience') ?: $url]);
            if (! $token->successful() || substr_count(trim($token->body()), '.') !== 2) {
                throw new \RuntimeException('Cloud Run AI identity token unavailable.', 503);
            }
            $request = $request->withHeaders(['X-Serverless-Authorization' => 'Bearer '.trim($token->body())]);
        }
        $response = $request
            ->attach('file', $bytes, 'evidence.'.pathinfo($photo->file_path, PATHINFO_EXTENSION), ['Content-Type' => $photo->mime])
            ->post($url.'/api/v1/analyses/file');
        if (! $response->successful() || strlen($response->body()) > 8000000 || ! is_array($response->json())) {
            throw new \RuntimeException('AI response unavailable or incompatible.', $response->successful() ? 502 : $response->status());
        }
        $data = $response->json();
        $validator = Validator::make($data, [
            'request_id' => ['required', 'uuid'], 'status' => ['required', 'in:completed'], 'model_id' => ['required', 'string', 'max:255'],
            'trained_model_id' => ['required', 'string', 'max:255'], 'image_sha256' => ['required', 'string', 'size:64'],
            'detections' => ['present', 'array', 'max:10000'], 'detections.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'detections.*.class_name' => ['required', 'string', 'max:200'], 'detections.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'detections.*.box.x1' => ['required', 'integer', 'min:0'], 'detections.*.box.y1' => ['required', 'integer', 'min:0'],
            'detections.*.box.x2' => ['required', 'integer', 'min:0'], 'detections.*.box.y2' => ['required', 'integer', 'min:0'],
            'annotated_image_url' => ['nullable', 'url'],
        ]);
        if ($validator->fails() || ! hash_equals($photo->image_sha256, $data['image_sha256'] ?? '')) {
            throw new \RuntimeException('AI contract or input hash mismatch.');
        }
        if (! empty($data['annotated_image_url']) && (parse_url($data['annotated_image_url'], PHP_URL_SCHEME) !== 'https'
            || parse_url($data['annotated_image_url'], PHP_URL_HOST) !== 'res.cloudinary.com')) {
            throw new \RuntimeException('AI returned an invalid annotated-image origin.');
        }

        return $data;
    }
}
