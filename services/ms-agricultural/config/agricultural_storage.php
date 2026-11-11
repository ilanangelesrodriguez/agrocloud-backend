<?php

return [
    'driver' => env('AGRICULTURAL_STORAGE_DRIVER', 'local'),
    'url' => env('CLOUDINARY_URL'),
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'prefix' => env('AGRICULTURAL_CLOUDINARY_PREFIX', 'agrocloud/agricultural'),
];
