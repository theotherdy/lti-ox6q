<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppAssetManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.default', 'public');
        Config::set('media.disk', 'public');
        Config::set('media.max_dimension', 1000);
        Storage::fake('public');
    }

    public function test_upload_requires_rights_basis(): void
    {
        $token = $this->buildAuthToken(true);
        $appId = $this->createApp();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post("/api/apps/{$appId}/assets/image", [
                'file' => UploadedFile::fake()->image('diagram.png', 1200, 900),
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['rights_basis']);
    }

    public function test_upload_requires_cc_license_when_creative_commons_selected(): void
    {
        $token = $this->buildAuthToken(true);
        $appId = $this->createApp();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post("/api/apps/{$appId}/assets/image", [
                'file' => UploadedFile::fake()->image('diagram.png', 1200, 900),
                'rights_basis' => 'creative_commons',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['cc_license']);
    }

    public function test_upload_persists_rights_metadata_and_list_returns_it(): void
    {
        $token = $this->buildAuthToken(true);
        $appId = $this->createApp();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/apps/{$appId}/assets/image", [
                'file' => UploadedFile::fake()->image('diagram.png', 2400, 1600),
                'label' => 'Main diagram',
                'alt' => 'A large diagram',
                'rights_basis' => 'creative_commons',
                'cc_license' => 'cc_by',
                'copyright_holder' => 'Example University',
                'rights_note' => 'Used under CC BY terms.',
            ]);

        $response->assertStatus(201);
        $assetId = $response->json('asset.id');
        $this->assertIsString($assetId);

        $row = DB::table('app_assets')->where('id', $assetId)->first();
        $this->assertNotNull($row);
        $this->assertSame('creative_commons', $row->rights_basis);
        $this->assertSame('cc_by', $row->cc_license);
        $this->assertSame('Example University', $row->copyright_holder);
        $this->assertSame('Main diagram', $row->label);
        $this->assertLessThanOrEqual(1000, (int) $row->width);
        $this->assertLessThanOrEqual(1000, (int) $row->height);
        $this->assertContains($row->mime_optimized, ['image/webp', 'image/png', 'image/jpeg']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/apps/{$appId}/assets")
            ->assertOk()
            ->assertJsonPath('assets.0.id', $assetId)
            ->assertJsonPath('assets.0.rights_basis', 'creative_commons')
            ->assertJsonPath('assets.0.cc_license', 'cc_by')
            ->assertJsonPath('assets.0.copyright_holder', 'Example University');
    }

    public function test_non_instructor_cannot_manage_assets(): void
    {
        $token = $this->buildAuthToken(false);
        $appId = $this->createApp();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/apps/{$appId}/assets")
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/apps/{$appId}/assets/image", [
                'file' => UploadedFile::fake()->image('diagram.png', 1200, 900),
                'rights_basis' => 'public_domain',
            ])
            ->assertStatus(403);
    }

    public function test_delete_removes_database_record_and_file(): void
    {
        $token = $this->buildAuthToken(true);
        $appId = $this->createApp();

        $upload = $this->withHeader('Authorization', "Bearer {$token}")
            ->post("/api/apps/{$appId}/assets/image", [
                'file' => UploadedFile::fake()->image('diagram.png', 1200, 900),
                'rights_basis' => 'public_domain',
            ])
            ->assertStatus(201);

        $assetId = $upload->json('asset.id');
        $path = DB::table('app_assets')->where('id', $assetId)->value('path_optimized');
        $this->assertTrue(Storage::disk('public')->exists($path));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/apps/{$appId}/assets/{$assetId}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('app_assets', ['id' => $assetId]);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    private function createApp(): int
    {
        return DB::table('apps')->insertGetId([
            'title' => 'Asset app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildAuthToken(bool $isInstructor): string
    {
        $secret = 'test-local-secret-asset-123456789012345';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return JWT::encode([
            'iss' => 'http://localhost',
            'sub' => $isInstructor ? 'instructor-user' : 'learner-user',
            'iat' => time(),
            'exp' => time() + 3600,
            'roles' => $isInstructor ? ['Instructor'] : ['Learner'],
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-assets',
                'resource_link_id' => 'resource-assets',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => $isInstructor,
            ],
        ], $secret, 'HS256');
    }
}
