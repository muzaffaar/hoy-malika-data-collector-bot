<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_recordings', function (Blueprint $t) {
            $t->string('sample_type')->default('WAKE_WORD')->index()->after('participant_id');
        });
        Schema::table('participants', function (Blueprint $t) {
            $t->string('collection_mode')->default('WAKE_WORD')->after('onboarding_state');
            $t->unsignedInteger('hard_negative_count')->default(0)->index()->after('recording_count');
        });
    }

    public function down(): void
    {
        Schema::table('voice_recordings', function (Blueprint $t) {
            $t->dropColumn('sample_type');
        });
        Schema::table('participants', function (Blueprint $t) {
            $t->dropColumn(['collection_mode', 'hard_negative_count']);
        });
    }
};
