<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resource_link_apps', function (Blueprint $table) {
            $table->id();
            $table->string('issuer');
            $table->string('deployment_id');
            $table->string('resource_link_id');
            $table->unsignedBigInteger('app_id');
            $table->timestamps();

            $table->unique(['issuer', 'deployment_id', 'resource_link_id'], 'resource_link_apps_unique');
            $table->index('app_id');
            $table->foreign('app_id')->references('id')->on('learning_apps')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_link_apps');
    }
};
