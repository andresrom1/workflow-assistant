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
        Schema::create('coverage_documents', function (Blueprint $table) {
            $table->id();
            $table->string('company_slug')->index();
            $table->string('company_name');
            $table->string('document_type'); // insert, asistencia, manual, general
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('storage_disk')->default('local');
            $table->string('mime_type');
            $table->longText('extracted_content')->nullable();
            $table->string('extraction_status')->default('pending'); // pending, completed, failed, manual
            $table->string('extraction_mode')->default('ai'); // ai, manual
            $table->string('extraction_provider')->default('openai'); // openai, anthropic, gemini
            $table->string('version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();

            $table->index(['company_slug', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coverage_documents');
    }
};
