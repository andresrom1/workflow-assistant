<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('audio_eligible')->default(false)->after('type');

            // Composite index for the sliding window query:
            // WHERE conversation_id = ? AND direction = 'outbound' AND audio_eligible = true
            // ORDER BY created_at DESC LIMIT 10
            $table->index(
                ['conversation_id', 'direction', 'audio_eligible', 'created_at'],
                'messages_audio_eligible_window_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_audio_eligible_window_index');
            $table->dropColumn('audio_eligible');
        });
    }
};
