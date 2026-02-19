<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_states', function (Blueprint $table) {
            $table->boolean('is_instructor')->default(false);
            $table->text('roles_json')->nullable();
            $table->index('is_instructor', 'app_states_is_instructor_idx');
            $table->index(['app_id', 'is_instructor'], 'app_states_app_id_is_instructor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('app_states', function (Blueprint $table) {
            $table->dropIndex('app_states_app_id_is_instructor_idx');
            $table->dropIndex('app_states_is_instructor_idx');
            $table->dropColumn('roles_json');
            $table->dropColumn('is_instructor');
        });
    }
};
