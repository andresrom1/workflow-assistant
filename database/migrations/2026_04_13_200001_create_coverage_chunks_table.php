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
        Schema::create('coverage_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coverage_document_id')->constrained('coverage_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->vector('embedding', 1536);
            $table->json('metadata')->nullable(); // {section: "Robo Parcial", page: 12}
            $table->timestamps();

            $table->index('coverage_document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coverage_chunks');
    }
};
