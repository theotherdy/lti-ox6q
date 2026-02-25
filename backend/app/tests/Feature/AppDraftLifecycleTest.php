<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppDraftLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_create_draft_returns_uninserted_draft_app(): void
    {
        $token = $this->buildAuthToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/apps/draft', ['title' => 'My draft']);

        $response->assertStatus(201)
            ->assertJsonPath('title', 'My draft')
            ->assertJsonPath('lifecycle_status', 'draft_uninserted');

        $id = $response->json('id');
        $this->assertDatabaseHas('apps', [
            'id' => $id,
            'title' => 'My draft',
            'lifecycle_status' => 'draft_uninserted',
        ]);
    }

    public function test_delete_draft_removes_app_and_asset_files(): void
    {
        $token = $this->buildAuthToken();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Draft app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'lifecycle_status' => 'draft_uninserted',
            'inserted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assetId = 'asset-draft-1';
        $path = "apps/{$appId}/assets/{$assetId}/optimized.webp";
        Storage::disk('public')->put($path, 'fake-image-bytes');

        DB::table('app_assets')->insert([
            'id' => $assetId,
            'app_id' => $appId,
            'kind' => 'image',
            'disk' => 'public',
            'path_optimized' => $path,
            'path_original' => null,
            'url_optimized' => '/storage/' . $path,
            'url_original' => null,
            'mime_original' => 'image/png',
            'mime_optimized' => 'image/webp',
            'bytes_original' => 100,
            'bytes_optimized' => 90,
            'width' => 100,
            'height' => 100,
            'checksum_sha256' => str_repeat('b', 64),
            'label' => 'Draft asset',
            'alt_text' => null,
            'rights_basis' => 'public_domain',
            'cc_license' => null,
            'copyright_holder' => null,
            'rights_note' => null,
            'rights_declared_by_sub' => 'editor-user',
            'rights_declared_at' => now(),
            'created_by_sub' => 'editor-user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(Storage::disk('public')->exists($path));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/apps/{$appId}/draft")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('apps', ['id' => $appId]);
        $this->assertDatabaseMissing('app_assets', ['id' => $assetId]);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_delete_draft_rejects_inserted_apps(): void
    {
        $token = $this->buildAuthToken();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Inserted app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'lifecycle_status' => 'inserted',
            'inserted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/apps/{$appId}/draft")
            ->assertStatus(422)
            ->assertJson(['error' => 'Only uninserted drafts can be deleted via this endpoint.']);
    }

    private function buildAuthToken(): string
    {
        $secret = 'test-local-secret-draft-12345678901234';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return JWT::encode([
            'iss' => 'http://localhost',
            'sub' => 'editor-user',
            'iat' => time(),
            'exp' => time() + 3600,
            'roles' => ['Instructor'],
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-draft',
                'resource_link_id' => 'resource-draft',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
