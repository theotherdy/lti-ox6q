<?php

return [
    'disk' => env('MEDIA_DISK', 'public'),
    'max_upload_mb' => (int) env('MEDIA_MAX_UPLOAD_MB', 12),
    'max_dimension' => (int) env('MEDIA_MAX_DIMENSION', 1920),
    'webp_quality' => (int) env('MEDIA_WEBP_QUALITY', 82),
    'max_assets_per_app' => (int) env('MEDIA_MAX_ASSETS_PER_APP', 50),
    'max_total_mb_per_app' => (int) env('MEDIA_MAX_TOTAL_MB_PER_APP', 150),
];
