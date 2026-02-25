<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AppAssetImageService
{
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const RIGHTS_BASIS = [
        'copyright_holder',
        'permission_obtained',
        'public_domain',
        'instructional_exception',
        'creative_commons',
    ];

    public const CC_LICENSES = [
        'cc_by',
        'cc_by_sa',
        'cc_by_nd',
        'cc_by_nc',
        'cc_by_nc_sa',
        'cc_by_nc_nd',
        'cc0',
    ];

    /**
     * @return array<string, mixed>
     */
    public function processUpload(int $appId, string $assetId, UploadedFile $file): array
    {
        $disk = (string) config('media.disk', 'public');
        $maxBytes = ((int) config('media.max_upload_mb', 12)) * 1024 * 1024;

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Uploaded image exceeds configured size limit.');
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        if (!in_array($mime, self::SUPPORTED_MIME_TYPES, true)) {
            throw new RuntimeException('Unsupported image format. Allowed formats: JPEG, PNG, WebP.');
        }

        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        $decoded = imagecreatefromstring($raw);
        if ($decoded === false) {
            throw new RuntimeException('Unable to decode image.');
        }

        $sourceWidth = imagesx($decoded);
        $sourceHeight = imagesy($decoded);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($decoded);
            throw new RuntimeException('Invalid image dimensions.');
        }

        [$targetWidth, $targetHeight] = $this->fitWithin($sourceWidth, $sourceHeight, (int) config('media.max_dimension', 1920));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($decoded);
            throw new RuntimeException('Unable to allocate image canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

        imagecopyresampled($canvas, $decoded, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $quality = max(1, min(100, (int) config('media.webp_quality', 82)));
        ob_start();
        $webpOk = imagewebp($canvas, null, $quality);
        $webpBinary = $webpOk ? ob_get_clean() : null;
        if (!$webpOk) {
            ob_end_clean();
        }

        imagedestroy($canvas);
        imagedestroy($decoded);

        $basePath = "apps/{$appId}/assets/{$assetId}";
        $checksum = hash('sha256', $raw);

        if (is_string($webpBinary) && $webpBinary !== '') {
            $optimizedPath = "{$basePath}/optimized.webp";
            Storage::disk($disk)->put($optimizedPath, $webpBinary, ['visibility' => 'public']);

            return [
                'disk' => $disk,
                'path_optimized' => $optimizedPath,
                'path_original' => null,
                'url_optimized' => Storage::disk($disk)->url($optimizedPath),
                'url_original' => null,
                'mime_original' => $mime,
                'mime_optimized' => 'image/webp',
                'bytes_original' => strlen($raw),
                'bytes_optimized' => strlen($webpBinary),
                'width' => $targetWidth,
                'height' => $targetHeight,
                'checksum_sha256' => $checksum,
            ];
        }

        $ext = $this->extensionFromMime($mime);
        $fallbackPath = "{$basePath}/fallback.{$ext}";
        Storage::disk($disk)->put($fallbackPath, $raw, ['visibility' => 'public']);

        return [
            'disk' => $disk,
            'path_optimized' => $fallbackPath,
            'path_original' => $fallbackPath,
            'url_optimized' => Storage::disk($disk)->url($fallbackPath),
            'url_original' => Storage::disk($disk)->url($fallbackPath),
            'mime_original' => $mime,
            'mime_optimized' => $mime,
            'bytes_original' => strlen($raw),
            'bytes_optimized' => strlen($raw),
            'width' => $targetWidth,
            'height' => $targetHeight,
            'checksum_sha256' => $checksum,
        ];
    }

    public function assertAppQuotaAvailable(int $appId, int $incomingBytes): void
    {
        $maxAssets = (int) config('media.max_assets_per_app', 50);
        $maxTotalBytes = ((int) config('media.max_total_mb_per_app', 150)) * 1024 * 1024;

        $stats = DB::table('app_assets')
            ->where('app_id', $appId)
            ->selectRaw('COUNT(*) as asset_count, COALESCE(SUM(bytes_optimized), 0) as total_bytes')
            ->first();

        $assetCount = (int) ($stats->asset_count ?? 0);
        $totalBytes = (int) ($stats->total_bytes ?? 0);

        if ($assetCount >= $maxAssets) {
            throw new RuntimeException('App image limit reached. Delete an image before uploading another.');
        }

        if ($totalBytes + $incomingBytes > $maxTotalBytes) {
            throw new RuntimeException('App image storage quota exceeded.');
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function fitWithin(int $width, int $height, int $maxDimension): array
    {
        if ($maxDimension <= 0) {
            return [$width, $height];
        }
        if ($width <= $maxDimension && $height <= $maxDimension) {
            return [$width, $height];
        }

        $scale = min($maxDimension / $width, $maxDimension / $height);
        return [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}
