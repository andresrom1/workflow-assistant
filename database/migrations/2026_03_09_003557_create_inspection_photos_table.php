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
        Schema::create('inspection_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('photo_key', 50);
            $table->string('cloudinary_public_id');
            $table->text('cloudinary_url');
            $table->enum('status', ['temp', 'confirmed'])->default('temp');
            $table->string('uploaded_by_ip', 45)->nullable(); // IPv6 safe
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['quote_id', 'photo_key']); // evita duplicados
            $table->index(['status', 'created_at']);   // para el cleanup query
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inspection_photos');
    }
};
