<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lti_users', function (Blueprint $table) {
            $table->id();
            $table->string('sub')->unique();
            $table->timestamps();
        });

        Schema::create('app_states_new', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('lti_user_id');
            $table->longText('state_json');
            $table->timestamps();

            $table->unique(['app_id', 'lti_user_id']);
            $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
            $table->foreign('lti_user_id')->references('id')->on('lti_users')->onDelete('cascade');
        });

        if (Schema::hasTable('app_states')) {
            $states = DB::table('app_states')->get();
            foreach ($states as $state) {
                $userId = DB::table('lti_users')->where('sub', $state->user_sub)->value('id');
                if (!$userId) {
                    $userId = DB::table('lti_users')->insertGetId([
                        'sub' => $state->user_sub,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('app_states_new')->insert([
                    'app_id' => $state->app_id,
                    'lti_user_id' => $userId,
                    'state_json' => $state->state_json,
                    'created_at' => $state->created_at ?? now(),
                    'updated_at' => $state->updated_at ?? now(),
                ]);
            }

            Schema::drop('app_states');
        }

        Schema::rename('app_states_new', 'app_states');
    }

    public function down(): void
    {
        Schema::create('app_states_old', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->string('user_sub');
            $table->longText('state_json');
            $table->timestamps();

            $table->unique(['app_id', 'user_sub']);
            $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
        });

        if (Schema::hasTable('app_states')) {
            $states = DB::table('app_states')->get();
            foreach ($states as $state) {
                $sub = DB::table('lti_users')->where('id', $state->lti_user_id)->value('sub');
                if (!$sub) {
                    $sub = 'unknown';
                }

                DB::table('app_states_old')->insert([
                    'app_id' => $state->app_id,
                    'user_sub' => $sub,
                    'state_json' => $state->state_json,
                    'created_at' => $state->created_at ?? now(),
                    'updated_at' => $state->updated_at ?? now(),
                ]);
            }

            Schema::drop('app_states');
        }

        Schema::rename('app_states_old', 'app_states');
        Schema::dropIfExists('lti_users');
    }
};
