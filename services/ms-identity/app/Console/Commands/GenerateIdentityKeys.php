<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateIdentityKeys extends Command
{
    protected $signature = 'identity:keys';

    protected $description = 'Generate the RSA signing key pair without overwriting existing keys';

    public function handle(): int
    {
        $privatePath = config('identity.private_key_path');
        $publicPath = config('identity.public_key_path');
        if (file_exists($privatePath) || file_exists($publicPath)) {
            $this->error('A key already exists. Manage key rotation explicitly.');

            return self::FAILURE;
        }
        foreach (array_unique([dirname($privatePath), dirname($publicPath)]) as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Cannot create signing-key directory.');
            }
        }
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('RSA key generation failed.');
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details)) {
            throw new \RuntimeException('RSA public key extraction failed.');
        }
        $handle = fopen($privatePath, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Private key already exists or cannot be created.');
        }
        chmod($privatePath, 0600);
        fwrite($handle, $private);
        fclose($handle);
        $handle = fopen($publicPath, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Public key already exists or cannot be created.');
        }
        fwrite($handle, $details['key']);
        fclose($handle);
        chmod($publicPath, 0644);
        $this->info('Signing keys created. Back up and mount them securely; never commit the private key.');

        return self::SUCCESS;
    }
}
