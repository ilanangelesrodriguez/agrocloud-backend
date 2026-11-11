<?php

namespace App\Modules\Evidences;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EvidenceStorage
{
    private const MaxBytes = 3072 * 1024;

    public function store(UploadedFile $file, int $company, int $inspection): string
    {
        $name = Str::uuid().'.'.$file->extension();

        return match (config('agricultural_storage.driver')) {
            'local' => Storage::disk('agricultural')->putFileAs("$company/$inspection", $file, $name)
                ?: throw new RuntimeException('Evidence storage unavailable.'),
            'cloudinary' => $this->upload(file_get_contents($file->getRealPath()), "$company/$inspection/$name", cleanupOnFailure: true),
            default => throw new RuntimeException('Unsupported evidence storage driver.'),
        };
    }

    public function upload(string $bytes, string $key, bool $cleanupOnFailure = false): string
    {
        $prefix = trim((string) config('agricultural_storage.prefix'), '/');
        $format = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $format = $format === 'jpeg' ? 'jpg' : $format;
        $id = $prefix.'/'.substr($key, 0, -(strlen(pathinfo($key, PATHINFO_EXTENSION)) + 1));
        if (! preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $id)
            || ! in_array($format, ['jpg', 'png', 'webp'], true) || strlen($bytes) > self::MaxBytes || $bytes === '' || strlen("cloudinary:$id.$format") > 255) {
            throw new RuntimeException('Invalid evidence asset.');
        }

        [$base, $apiKey, $secret] = $this->credentials();
        $reference = "cloudinary:$id.$format";
        if ($cleanupOnFailure) {
            logger()->info('Cloudinary evidence upload started.', ['reference' => $reference]);
        }

        try {
            $response = $this->http()->withBasicAuth($apiKey, $secret)
                ->attach('file', $bytes, 'evidence.'.$format)
                ->post($base.'/image/upload', [
                    'public_id' => $id,
                    'type' => 'authenticated',
                    'overwrite' => 'false',
                ]);
            $uploadStatus = $response->status();
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $uploadStatus = null;
        }

        try {
            // Cloudinary may have saved the object despite an error or a lost response.
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $this->read($reference)))) {
                throw new RuntimeException('Cloudinary evidence hash mismatch.');
            }
        } catch (\Exception) {
            logger()->warning('Cloudinary evidence could not be confirmed.', [
                'reference' => $reference,
                'upload_status' => $uploadStatus,
            ]);

            if ($cleanupOnFailure) {
                try {
                    $this->delete($reference);
                } catch (\Exception) {
                    logger()->warning('Cloudinary evidence requires cleanup.', ['reference' => $reference]);
                }
            }

            throw new RuntimeException('Cloudinary evidence could not be confirmed.');
        }

        return $reference;
    }

    public function read(string $reference): string
    {
        if (! str_starts_with($reference, 'cloudinary:')) {
            $stream = Storage::disk('agricultural')->readStream($reference);
            if (! is_resource($stream)) {
                throw new RuntimeException('Local evidence unavailable.');
            }
            try {
                $bytes = stream_get_contents($stream, self::MaxBytes + 1);
            } finally {
                fclose($stream);
            }
        } else {
            [$base, $apiKey, $secret] = $this->credentials();
            [$id, $format] = $this->asset($reference);
            $parameters = [
                'expires_at' => time() + 60,
                'format' => $format,
                'public_id' => $id,
                'timestamp' => time(),
                'type' => 'authenticated',
            ];
            $signature = sha1(urldecode(http_build_query($parameters)).$secret);
            $response = $this->http()->withOptions(['stream' => true, 'read_timeout' => 5])
                ->get($base.'/image/download', [...$parameters, 'api_key' => $apiKey, 'signature' => $signature]);
            $stream = $response->toPsrResponse()->getBody();
            try {
                if (! $response->successful()) {
                    throw new RuntimeException('Cloudinary evidence unavailable.');
                }
                $bytes = '';
                $deadline = microtime(true) + 15;
                while (! $stream->eof() && strlen($bytes) <= self::MaxBytes && microtime(true) < $deadline) {
                    $chunk = $stream->read(min(65536, self::MaxBytes + 1 - strlen($bytes)));
                    if ($chunk === '' && ! $stream->eof()) {
                        throw new RuntimeException('Cloudinary evidence download stalled.');
                    }
                    $bytes .= $chunk;
                }
                if (! $stream->eof()) {
                    throw new RuntimeException('Cloudinary evidence download exceeded limits.');
                }
            } finally {
                $stream->close();
            }
        }
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MaxBytes) {
            throw new RuntimeException('Evidence download exceeded limits or was empty.');
        }

        return $bytes;
    }

    public function delete(string $reference): void
    {
        if (! str_starts_with($reference, 'cloudinary:')) {
            Storage::disk('agricultural')->delete($reference);

            return;
        }
        [$base, $apiKey, $secret] = $this->credentials();
        [$id] = $this->asset($reference);
        $response = $this->http()->withBasicAuth($apiKey, $secret)->asForm()
            ->post($base.'/image/destroy', ['public_id' => $id, 'type' => 'authenticated']);
        if (! $response->successful() || ! in_array($response->json('result'), ['ok', 'not found'], true)) {
            throw new RuntimeException('Cloudinary evidence cleanup failed.');
        }
    }

    /** @return array{string, string} */
    private function asset(string $reference): array
    {
        if (! preg_match('~^cloudinary:([a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)\.(jpg|png|webp)$~D', $reference, $matches)) {
            throw new RuntimeException('Invalid Cloudinary evidence reference.');
        }

        return [$matches[1], $matches[2]];
    }

    /** @return array{string, string, string} */
    private function credentials(): array
    {
        $url = parse_url((string) config('agricultural_storage.url'));
        $cloud = $url['host'] ?? '';
        $configured = config('agricultural_storage.cloud_name');
        if (($url['scheme'] ?? '') !== 'cloudinary' || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $cloud)
            || empty($url['user']) || empty($url['pass']) || ($configured && $configured !== $cloud)) {
            throw new RuntimeException('Invalid Cloudinary configuration.');
        }

        return ['https://api.cloudinary.com/v1_1/'.$cloud, rawurldecode($url['user']), rawurldecode($url['pass'])];
    }

    private function http(): PendingRequest
    {
        return Http::withoutRedirecting()->connectTimeout(3)->timeout(15);
    }
}
