<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StructuredHotspotQuestionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public function test_save_revision_accepts_valid_hotspot_question(): void
    {
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Structured hotspot app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode(['kind' => 'structured_question_set', 'schema_version' => '2026-02-18', 'title' => 'Structured hotspot app', 'questions' => [], 'meta' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'kind' => 'structured_question_set',
            'schema_version' => '2026-02-18',
            'title' => 'Updated hotspot',
            'questions' => [[
                'id' => 'q-hotspot',
                'question_type' => 'image_hotspot_single',
                'prompt_html' => '<p>Select the highlighted area.</p>',
                'points_possible' => 1,
                'shuffle_options' => false,
                'image' => [
                    'asset_id' => 'asset-1',
                    'url' => 'https://cdn.example.org/img.webp',
                    'alt' => 'Diagram',
                    'width' => 1000,
                    'height' => 600,
                ],
                'hotspots' => [
                    ['id' => 'hs1', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2, 'label' => 'Top left'],
                    ['id' => 'hs2', 'x' => 0.5, 'y' => 0.5, 'w' => 0.3, 'h' => 0.3, 'label' => 'Center'],
                ],
                'correct_hotspot_id' => 'hs2',
                'reveal_correct_after_two_incorrect_attempts' => true,
            ]],
            'meta' => ['mode' => 'self_test'],
        ];

        $this->putJson("/api/apps/{$appId}/save-revision", $payload)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_save_revision_rejects_out_of_bounds_hotspot_geometry(): void
    {
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Structured hotspot app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode(['kind' => 'structured_question_set', 'schema_version' => '2026-02-18', 'title' => 'Structured hotspot app', 'questions' => [], 'meta' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'kind' => 'structured_question_set',
            'schema_version' => '2026-02-18',
            'title' => 'Updated hotspot',
            'questions' => [[
                'id' => 'q-hotspot',
                'question_type' => 'image_hotspot_single',
                'prompt_html' => '<p>Select the highlighted area.</p>',
                'points_possible' => 1,
                'image' => [
                    'asset_id' => 'asset-1',
                    'url' => 'https://cdn.example.org/img.webp',
                ],
                'hotspots' => [
                    ['id' => 'hs1', 'x' => 0.9, 'y' => 0.9, 'w' => 0.2, 'h' => 0.2, 'label' => 'Out of bounds'],
                ],
                'correct_hotspot_id' => 'hs1',
                'reveal_correct_after_two_incorrect_attempts' => true,
            ]],
            'meta' => ['mode' => 'self_test'],
        ];

        $this->putJson("/api/apps/{$appId}/save-revision", $payload)
            ->assertStatus(422)
            ->assertJson(['error' => 'image_hotspot_single hotspot coordinates must stay within normalized [0..1] bounds']);
    }
}
