<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('lifecycle_status')->default('inserted')->after('structured_json');
            $table->timestamp('inserted_at')->nullable()->after('lifecycle_status');
        });

        DB::table('apps')->whereNull('inserted_at')->update([
            'inserted_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_status', 'inserted_at']);
        });
    }
};
