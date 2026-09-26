<?php

declare(strict_types=1);

use App\Support\Env;

/** The website CMS: where the media library keeps public images and how big they may be. */
return [
    'media' => [
        // Must be inside public/ so the web server serves the files directly; `url` is the matching public prefix.
        'root' => Env::get('CMS_MEDIA_ROOT', dirname(__DIR__) . '/public/media'),
        'url' => '/media',
        'max_kb' => Env::int('CMS_MEDIA_MAX_KB', 8192),
        'max_side' => 2560,          // longer uploads are scaled down to this
        'thumb_side' => 400,
        'max_pixels' => 40_000_000,  // refuse decompression bombs before decoding
        'jpeg_quality' => 82,
        'webp_quality' => 80,
    ],
];
