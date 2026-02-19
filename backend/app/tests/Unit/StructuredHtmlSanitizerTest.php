<?php

namespace Tests\Unit;

use App\Services\StructuredHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class StructuredHtmlSanitizerTest extends TestCase
{
    public function test_sanitizer_preserves_table_markup_and_safe_https_images(): void
    {
        $sanitizer = new StructuredHtmlSanitizer();

        $input = '<p>Data</p><table><thead><tr><th scope="col">A</th><th>B</th></tr></thead><tbody><tr><td colspan="2">Value</td></tr></tbody></table><img src="https://cdn.example.org/q.png" alt="img">';
        $output = $sanitizer->sanitize($input);

        $this->assertStringContainsString('<table>', $output);
        $this->assertStringContainsString('<thead>', $output);
        $this->assertStringContainsString('scope="col"', $output);
        $this->assertStringContainsString('colspan="2"', $output);
        $this->assertStringContainsString('src="https://cdn.example.org/q.png"', $output);
    }

    public function test_sanitizer_removes_unsafe_tags_links_event_handlers_and_non_https_images(): void
    {
        $sanitizer = new StructuredHtmlSanitizer();

        $input = '<script>alert(1)</script><a href="https://example.org">link</a><img src="http://bad.example/x.png" onerror="alert(2)"><img src="data:image/png;base64,abc"><img src="/relative.png">';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('<a ', $output);
        $this->assertStringNotContainsString('onerror=', $output);
        $this->assertStringNotContainsString('http://bad.example', $output);
        $this->assertStringNotContainsString('data:image', $output);
        $this->assertStringNotContainsString('/relative.png', $output);
    }
}
