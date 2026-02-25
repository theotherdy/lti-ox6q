<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('kind')->default('image');

            $table->string('disk');
            $table->string('path_optimized');
            $table->string('path_original')->nullable();
            $table->string('url_optimized');
            $table->string('url_original')->nullable();
            $table->string('mime_original')->nullable();
            $table->string('mime_optimized');
            $table->unsignedBigInteger('bytes_original')->nullable();
            $table->unsignedBigInteger('bytes_optimized');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('checksum_sha256', 64);

            $table->string('label')->nullable();
            $table->string('alt_text')->nullable();

            $table->string('rights_basis');
            $table->string('cc_license')->nullable();
            $table->string('copyright_holder')->nullable();
            $table->text('rights_note')->nullable();
            $table->string('rights_declared_by_sub');
            $table->timestamp('rights_declared_at');

            $table->string('created_by_sub');
            $table->timestamps();

            $table->index(['app_id', 'created_at']);
            $table->index(['app_id', 'checksum_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_assets');
    }
};
