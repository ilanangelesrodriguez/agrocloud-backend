<?php

namespace App\Services\Profile;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AvatarStorage
{
    private function credentials(): array
    {
        $cloud = (string) config('services.cloudinary.cloud_name');
        $key = (string) config('services.cloudinary.api_key');
        $secret = (string) config('services.cloudinary.api_secret');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $cloud) && $key !== '' && strlen($secret) >= 16,
            503, 'Almacenamiento de fotografías no configurado.');
        return [$cloud, $key, $secret];
    }

    public function upload(UploadedFile $file, string $folder = 'agrocloud/perfiles'): array
    {
        [$cloud, $key, $secret] = $this->credentials();
        $timestamp = time();
        abort_unless(in_array($folder, ['agrocloud/perfiles', 'agrocloud/empresas'], true), 500);
        $signature = sha1('folder='.$folder.'&timestamp='.$timestamp.$secret);
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(20)
                ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post('https://api.cloudinary.com/v1_1/'.$cloud.'/image/upload', [
                    'api_key' => $key, 'timestamp' => $timestamp, 'folder' => $folder, 'signature' => $signature,
                ]);
        } catch (\Throwable $error) {
            Log::warning('Profile image storage unavailable', ['type' => get_class($error)]);
            throw new HttpException(503, 'No se pudo subir la fotografía.');
        }
        $data = $response->json();
        $url = is_array($data) ? ($data['secure_url'] ?? null) : null;
        $publicId = is_array($data) ? ($data['public_id'] ?? null) : null;
        abort_unless($response->successful() && is_string($url) && str_starts_with($url, 'https://res.cloudinary.com/'.$cloud.'/')
            && is_string($publicId) && str_starts_with($publicId, $folder.'/'), 502, 'El proveedor de imágenes no confirmó la subida.');
        return ['url' => $url, 'public_id' => $publicId];
    }

    public function deleteQuietly(?string $publicId, string $folder = 'agrocloud/perfiles'): void
    {
        if (! in_array($folder, ['agrocloud/perfiles', 'agrocloud/empresas'], true) || ! $publicId || ! str_starts_with($publicId, $folder.'/')) return;
        try {
            [$cloud, $key, $secret] = $this->credentials();
            $timestamp = time();
            Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(10)
                ->asForm()->post('https://api.cloudinary.com/v1_1/'.$cloud.'/image/destroy', [
                    'api_key' => $key, 'timestamp' => $timestamp, 'public_id' => $publicId,
                    'signature' => sha1('public_id='.$publicId.'&timestamp='.$timestamp.$secret),
                ]);
        } catch (\Throwable $error) {
            Log::warning('Profile image cleanup pending', ['type' => get_class($error)]);
        }
    }
}
