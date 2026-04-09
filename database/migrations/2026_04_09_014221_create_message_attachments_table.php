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
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('attachment_type'); // audio, image, document, video
            $table->string('external_media_id')->nullable(); // channel media ID (Meta, Telegram, etc.)
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->unsignedInteger('duration_seconds')->nullable(); // audio/video
            $table->string('storage_path')->nullable();
            $table->string('storage_url')->nullable();
            $table->text('transcription')->nullable(); // STT result for audio
            $table->string('processing_status')->default('pending'); // pending, processing, done, failed
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('processing_status', 'message_attachments_processing_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
