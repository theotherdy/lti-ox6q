<?php

namespace App\Http\Controllers;

use App\Services\AppAssetImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class AppAssetController extends Controller
{
    public function index(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }

        if (!$this->isInstructor($request)) {
            return response()->json(['error' => 'Only instructors can manage app images.'], 403);
        }

        $appId = (int) $appId;
        if (!DB::table('apps')->where('id', $appId)->exists()) {
            return response()->json(['error' => 'App not found'], 404);
        }

        $assets = DB::table('app_assets')
            ->where('app_id', $appId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($row) => $this->formatAsset($row))
            ->values();

        return response()->json(['assets' => $assets]);
    }

    public function uploadImage(Request $request, $appId, AppAssetImageService $service)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        if (!$this->isInstructor($request)) {
            return response()->json(['error' => 'Only instructors can manage app images.'], 403);
        }

        $appId = (int) $appId;
        if (!DB::table('apps')->where('id', $appId)->exists()) {
            return response()->json(['error' => 'App not found'], 404);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:' . (((int) config('media.max_upload_mb', 12)) * 1024),
            'rights_basis' => ['required', 'string', Rule::in(AppAssetImageService::RIGHTS_BASIS)],
            'cc_license' => [
                Rule::requiredIf(fn () => $request->input('rights_basis') === 'creative_commons'),
                'nullable',
                'string',
                Rule::in(AppAssetImageService::CC_LICENSES),
            ],
            'label' => 'nullable|string|max:200',
            'alt' => 'nullable|string|max:500',
            'copyright_holder' => 'nullable|string|max:255',
            'rights_note' => 'nullable|string|max:2000',
        ]);

        $incomingSize = (int) ($request->file('file')?->getSize() ?? 0);
        try {
            $service->assertAppQuotaAvailable($appId, max(0, $incomingSize));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $assetId = (string) Str::uuid();
        try {
            $processed = $service->processUpload($appId, $assetId, $request->file('file'));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $sub = (string) $request->attributes->get('auth_sub', 'unknown');
        $now = now();

        $record = [
            'id' => $assetId,
            'app_id' => $appId,
            'kind' => 'image',
            'disk' => $processed['disk'],
            'path_optimized' => $processed['path_optimized'],
            'path_original' => $processed['path_original'],
            'url_optimized' => $processed['url_optimized'],
            'url_original' => $processed['url_original'],
            'mime_original' => $processed['mime_original'],
            'mime_optimized' => $processed['mime_optimized'],
            'bytes_original' => $processed['bytes_original'],
            'bytes_optimized' => $processed['bytes_optimized'],
            'width' => $processed['width'],
            'height' => $processed['height'],
            'checksum_sha256' => $processed['checksum_sha256'],
            'label' => $validated['label'] ?? null,
            'alt_text' => $validated['alt'] ?? null,
            'rights_basis' => $validated['rights_basis'],
            'cc_license' => ($validated['rights_basis'] === 'creative_commons') ? ($validated['cc_license'] ?? null) : null,
            'copyright_holder' => $validated['copyright_holder'] ?? null,
            'rights_note' => $validated['rights_note'] ?? null,
            'rights_declared_by_sub' => $sub,
            'rights_declared_at' => $now,
            'created_by_sub' => $sub,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('app_assets')->insert($record);

        return response()->json(['asset' => $this->formatAsset((object) $record)], 201);
    }

    public function destroy(Request $request, $appId, string $assetId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        if (!$this->isInstructor($request)) {
            return response()->json(['error' => 'Only instructors can manage app images.'], 403);
        }

        $appId = (int) $appId;
        $asset = DB::table('app_assets')
            ->where('app_id', $appId)
            ->where('id', $assetId)
            ->first();

        if (!$asset) {
            return response()->json(['error' => 'Asset not found'], 404);
        }

        $disk = (string) $asset->disk;
        $paths = array_values(array_unique(array_filter([
            $asset->path_optimized,
            $asset->path_original,
        ])));

        foreach ($paths as $path) {
            Storage::disk($disk)->delete((string) $path);
        }

        DB::table('app_assets')
            ->where('app_id', $appId)
            ->where('id', $assetId)
            ->delete();

        return response()->json(['success' => true]);
    }

    private function isInstructor(Request $request): bool
    {
        return (bool) $request->attributes->get('auth_lti_is_instructor', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAsset(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'app_id' => (int) $row->app_id,
            'kind' => (string) $row->kind,
            'url' => (string) $row->url_optimized,
            'url_original' => $row->url_original,
            'mime' => (string) $row->mime_optimized,
            'mime_original' => $row->mime_original,
            'bytes' => (int) $row->bytes_optimized,
            'bytes_original' => $row->bytes_original,
            'width' => (int) $row->width,
            'height' => (int) $row->height,
            'label' => $row->label,
            'alt_text' => $row->alt_text,
            'rights_basis' => (string) $row->rights_basis,
            'cc_license' => $row->cc_license,
            'copyright_holder' => $row->copyright_holder,
            'rights_note' => $row->rights_note,
            'rights_declared_by_sub' => (string) $row->rights_declared_by_sub,
            'rights_declared_at' => is_string($row->rights_declared_at) ? $row->rights_declared_at : (string) $row->rights_declared_at,
            'created_by_sub' => (string) $row->created_by_sub,
            'created_at' => is_string($row->created_at) ? $row->created_at : (string) $row->created_at,
        ];
    }
}
