<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('lti_user_id');
            $table->longText('state_json');
            $table->timestamps();

            $table->unique(['app_id', 'lti_user_id']);
            $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
            $table->foreign('lti_user_id')->references('id')->on('lti_users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_states');
    }
};
